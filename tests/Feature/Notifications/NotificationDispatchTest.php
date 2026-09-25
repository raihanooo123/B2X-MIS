<?php

use App\Domain\Notifications\DeliveryEvents;
use App\Domain\Notifications\Notices\OrderConfirmed;
use App\Domain\Notifications\Notices\PasswordChanged;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationDispatcher;
use App\Domain\Notifications\Notifications;
use App\Domain\Notifications\NotificationStatus;
use App\Domain\Notifications\Recipient;
use App\Domain\Notifications\RecipientResolver;
use App\Jobs\SendNotification;
use App\Mail\NotificationMail;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function notificationOrder(array $attributes = []): Order
{
    return Order::factory()->create([
        'subtotal_net_minor' => 10000, 'tax_minor' => 2000, 'total_gross_minor' => 12000, ...$attributes,
    ]);
}

function logRow(string $status, string $recipient, array $attributes = []): NotificationLog
{
    return NotificationLog::query()->create([
        'notification_key' => 'order.confirmed', 'category' => 'transactional', 'channel' => 'email',
        'template_version' => '1', 'recipient' => $recipient, 'dedup_key' => (string) Str::ulid(),
        'status' => $status, ...$attributes,
    ]);
}

it('writes one queued row and one job per recipient, and never twice for a repeated event', function () {
    Queue::fake();
    $order = notificationOrder();

    (new Notifications)->orderConfirmed($order->id);
    (new Notifications)->orderConfirmed($order->id);

    $row = NotificationLog::query()->sole();
    expect($row->notification_key)->toBe('order.confirmed')
        ->and($row->status)->toBe('queued')
        ->and($row->user_id)->toBe($order->user_id)
        ->and($row->subject_type)->toBe('order')
        ->and($row->subject_id)->toBe($order->id)
        ->and($row->template_version)->toBe('1');
    Queue::assertPushed(SendNotification::class, 1);
});

it('queues nothing when the surrounding transaction rolls back', function () {
    Queue::fake();
    $order = notificationOrder();

    try {
        DB::transaction(function () use ($order) {
            (new Notifications)->orderConfirmed($order->id);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(NotificationLog::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('sends through the mailer and records the provider message id', function () {
    $order = notificationOrder();

    (new Notifications)->orderConfirmed($order->id);

    $row = NotificationLog::query()->sole();
    expect($row->status)->toBe('sent')
        ->and($row->attempts)->toBe(1)
        ->and($row->sent_at)->not->toBeNull()
        ->and($row->provider_message_id)->not->toBeNull();
});

it('marks a row failed when retries are exhausted', function () {
    $row = logRow('queued', 'someone@example.com');

    (new SendNotification($row->id, new OrderConfirmed(1), new Recipient('someone@example.com')))->failed(new RuntimeException('provider down'));

    expect($row->fresh()->status)->toBe('failed')
        ->and($row->fresh()->last_error)->toBe('provider down');
});

it('renders order content with formatted totals and no cost figures', function () {
    $order = notificationOrder(['total_cost_minor' => 4321]);

    $content = (new OrderConfirmed($order->id))->content(new Recipient('a@example.com'));
    $html = (new NotificationMail($content))->render();

    expect($content->subject)->toBe("Order {$order->order_number} confirmed")
        ->and($html)->toContain('£120.00')
        ->and($html)->not->toContain('43.21');
});

it('suppresses transactional mail to a hard-bounced address but still attempts security notices', function () {
    Queue::fake();
    $user = User::factory()->create(['email' => 'bounced@example.com']);
    logRow('bounced', 'bounced@example.com');
    $order = notificationOrder(['user_id' => $user->id]);

    (new Notifications)->orderConfirmed($order->id);
    (new Notifications)->toUser(new PasswordChanged($user->id), $user);

    expect(NotificationLog::query()->where('notification_key', 'order.confirmed')->where('user_id', $user->id)->value('status'))->toBe('suppressed')
        ->and(NotificationLog::query()->where('notification_key', 'auth.password_changed')->value('status'))->toBe('queued');
    Queue::assertPushed(SendNotification::class, 1);
});

it('lifts bounce suppression once a later message to the address is delivered', function () {
    Queue::fake();
    $user = User::factory()->create(['email' => 'back@example.com']);
    logRow('bounced', 'back@example.com');
    DB::table('notification_log')->update(['updated_at' => now()->subDay()]);
    logRow('delivered', 'back@example.com', ['delivered_at' => now()]);

    (new Notifications)->orderConfirmed(notificationOrder(['user_id' => $user->id])->id);

    expect(NotificationLog::query()->where('notification_key', 'order.confirmed')->where('user_id', $user->id)->value('status'))->toBe('queued');
});

it('prefers the company accounts email for invoice messages', function () {
    $company = Company::factory()->create(['accounts_email' => 'AP@Customer.example']);
    $owner = User::factory()->create();
    CompanyUser::factory()->owner()->create(['company_id' => $company->id, 'user_id' => $owner->id]);
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);

    $recipients = (new RecipientResolver)->invoiceRecipients($invoice);

    expect($recipients)->toHaveCount(1)
        ->and($recipients[0]->email)->toBe('ap@customer.example')
        ->and($recipients[0]->userId)->toBeNull();

    $company->update(['accounts_email' => null]);
    expect(array_map(fn (Recipient $r) => $r->userId, (new RecipientResolver)->invoiceRecipients($invoice)))->toBe([$owner->id]);
});

it('warns owners once when credit usage crosses 80% of the limit', function () {
    Queue::fake();
    $company = Company::factory()->create(['credit_limit_minor' => 100000]);
    $owner = User::factory()->create();
    CompanyUser::factory()->owner()->create(['company_id' => $company->id, 'user_id' => $owner->id]);
    $notifications = new Notifications;

    $notifications->creditUsageChanged($company->id, 100000, 50000, 79999, 'order:1'); // below
    $notifications->creditUsageChanged($company->id, 100000, 79999, 80000, 'order:2'); // crosses
    $notifications->creditUsageChanged($company->id, 100000, 80000, 95000, 'order:3'); // already over
    $notifications->creditUsageChanged($company->id, 100000, 70000, 85000, 'order:2'); // same cause, replayed
    $notifications->creditUsageChanged($company->id, 0, 0, 10, 'order:4');              // no limit

    $rows = NotificationLog::query()->where('notification_key', 'credit.limit_warning')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->sole()->user_id)->toBe($owner->id);
});

it('re-arms the credit warning after usage falls back below 80%', function () {
    Queue::fake();
    $company = Company::factory()->create(['credit_limit_minor' => 100000]);
    CompanyUser::factory()->owner()->create(['company_id' => $company->id, 'user_id' => User::factory()->create()->id]);

    (new Notifications)->creditUsageChanged($company->id, 100000, 70000, 90000, 'order:1');
    (new Notifications)->creditUsageChanged($company->id, 100000, 60000, 81000, 'order:2');

    expect(NotificationLog::query()->where('notification_key', 'credit.limit_warning')->count())->toBe(2);
});

it('sends no payment.received for a card order (05.12 §5.1.1)', function () {
    Queue::fake();
    $invoice = Invoice::factory()->create();

    (new Notifications)->paymentApplied($invoice->id, 1, 500, 'card');
    expect(NotificationLog::query()->count())->toBe(0);

    (new Notifications)->paymentApplied($invoice->id, 2, 500, 'bacs');
    expect(NotificationLog::query()->where('notification_key', 'payment.received')->exists())->toBeTrue();
});

it('sends credit.limit_reached to the accounts role at most once a day per company', function () {
    Queue::fake();
    $accounts = User::factory()->create();
    RoleUser::create(['role_id' => Role::factory()->create(['code' => 'accounts', 'name' => 'Accounts'])->id, 'user_id' => $accounts->id]);
    $company = Company::factory()->create();

    (new Notifications)->creditLimitReached($company->id, 50000);
    (new Notifications)->creditLimitReached($company->id, 60000);

    expect(NotificationLog::query()->where('notification_key', 'credit.limit_reached')->pluck('user_id')->all())->toBe([$accounts->id]);
});

it('applies Postmark events forward only, behind basic auth', function () {
    config(['services.postmark.webhook_user' => 'pm', 'services.postmark.webhook_password' => 'secret']);
    $row = logRow('sent', 'x@example.com', ['provider_message_id' => 'pm-123']);
    $auth = ['PHP_AUTH_USER' => 'pm', 'PHP_AUTH_PW' => 'secret'];

    $this->postJson('/api/v1/webhooks/postmark', ['RecordType' => 'Delivery', 'MessageID' => 'pm-123'])->assertUnauthorized();

    $this->withServerVariables($auth)->postJson('/api/v1/webhooks/postmark', ['RecordType' => 'SpamComplaint', 'MessageID' => 'pm-123'])->assertOk();
    $this->withServerVariables($auth)->postJson('/api/v1/webhooks/postmark', ['RecordType' => 'Delivery', 'MessageID' => 'pm-123', 'DeliveredAt' => now()->toIso8601String()])->assertOk();
    $this->withServerVariables($auth)->postJson('/api/v1/webhooks/postmark', ['RecordType' => 'Delivery', 'MessageID' => 'unknown'])->assertOk();

    expect($row->fresh()->status)->toBe('complained');
});

it('records only hard bounces from Postmark', function () {
    $row = logRow('sent', 'y@example.com', ['provider_message_id' => 'pm-456']);
    $events = new DeliveryEvents;

    expect($events->apply(NotificationChannel::Email, 'pm-456', NotificationStatus::Delivered, now()))->toBeTrue()
        ->and($events->apply(NotificationChannel::Email, 'pm-456', NotificationStatus::Delivered, now()))->toBeFalse()
        ->and($events->apply(NotificationChannel::Email, 'pm-456', NotificationStatus::Bounced, null, 'bounce: HardBounce'))->toBeTrue();

    config(['services.postmark.webhook_user' => 'pm', 'services.postmark.webhook_password' => 'secret']);
    $soft = logRow('sent', 'z@example.com', ['provider_message_id' => 'pm-789']);
    $this->withServerVariables(['PHP_AUTH_USER' => 'pm', 'PHP_AUTH_PW' => 'secret'])
        ->postJson('/api/v1/webhooks/postmark', ['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'MessageID' => 'pm-789'])->assertOk();

    expect($row->fresh()->status)->toBe('bounced')
        ->and($soft->fresh()->status)->toBe('sent');
});

it('sends invoice reminders once, for credit-terms invoices only', function () {
    Queue::fake();
    $company = Company::factory()->create();
    CompanyUser::factory()->owner()->create(['company_id' => $company->id, 'user_id' => User::factory()->create()->id]);
    $dueSoon = Invoice::factory()->create(['company_id' => $company->id, 'payment_terms' => 'net30', 'due_at' => now()->addDays(2)]);
    $overdue = Invoice::factory()->create(['company_id' => $company->id, 'payment_terms' => 'net30', 'due_at' => now()->subDays(10)]);
    Invoice::factory()->create(['company_id' => $company->id, 'payment_terms' => 'prepay', 'due_at' => now()->subDays(10)]);
    Invoice::factory()->paid()->create(['company_id' => $company->id, 'payment_terms' => 'net30', 'due_at' => now()->subDays(10)]);

    $this->artisan('notifications:invoice-reminders')->assertSuccessful();
    $this->artisan('notifications:invoice-reminders')->assertSuccessful();

    expect(NotificationLog::query()->where('notification_key', 'invoice.due_soon')->pluck('subject_id')->all())->toBe([$dueSoon->id])
        ->and(NotificationLog::query()->where('notification_key', 'invoice.overdue')->pluck('subject_id')->all())->toBe([$overdue->id]);
});

it('prunes log rows older than two years', function () {
    $old = logRow('delivered', 'old@example.com', ['queued_at' => now()->subYears(2)->subDay()]);
    $recent = logRow('delivered', 'new@example.com', ['queued_at' => now()->subYears(2)->addDay()]);

    $this->artisan('notifications:prune-log')->assertSuccessful();

    expect(NotificationLog::query()->pluck('id')->all())->toBe([$recent->id]);
});

it('skips recipients with no address and logs nothing', function () {
    Queue::fake();

    (new NotificationDispatcher)->send(new OrderConfirmed(1), []);

    expect(NotificationLog::query()->count())->toBe(0);
});
