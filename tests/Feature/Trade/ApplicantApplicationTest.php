<?php

use App\Models\Attachment;
use App\Models\B2bApplication;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/**
 * 05.17 §2 — the applicant's own application: only theirs, customer-safe
 * (never the internal reason, category or evidence); reply to an open
 * information request with bounded text and validated private documents,
 * back to in_review with the history kept and the reviewer told; withdraw
 * an open application only, after confirmation; the reapply date shown.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Storage::fake(config('documents.disk'));
    Mail::fake();
});

function aplReviewer(): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => (Role::query()->where('code', 'accounts')->first() ?? Role::factory()->create(['code' => 'accounts']))->id, 'user_id' => $user->id]);

    return $user;
}

function aplApplication(User $applicant, array $overrides = []): B2bApplication
{
    return B2bApplication::factory()->create($overrides + [
        'applicant_user_id' => $applicant->id,
        'status' => 'info_requested',
        'info_request' => 'Please send a recent bank statement.',
        'reviewer_user_id' => aplReviewer()->id,
        'review_note' => 'INTERNAL: director looks unusual',
        'submitted_at' => now()->subDays(3),
    ]);
}

it('shows the applicant their own application, customer-safe', function () {
    $applicant = User::factory()->create();
    $application = aplApplication($applicant);

    $response = $this->actingAs($applicant)->get('/trade/application')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Trade/Application/Status', false)
        ->where('application.id', $application->public_id)
        ->where('application.status', 'info_requested')
        ->where('application.info_request', 'Please send a recent bank statement.')
        ->where('application.can_reply', true)
        ->where('application.can_withdraw', true)
        ->where('auth.has_trade_application', true));

    expect(json_encode($response->viewData('page')['props']))->not->toContain('INTERNAL')->not->toContain('review_note');
});

it('never shows one person another person\'s application', function () {
    $owner = User::factory()->create();
    aplApplication($owner, ['company_name' => 'Owner Traders']);
    $stranger = User::factory()->create(['email' => 'same-domain@example.com']);

    $this->actingAs($stranger)->get('/trade/application')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('application', null));
    $this->actingAs($stranger)->post('/trade/application/reply', ['message' => 'Hello'])->assertNotFound();
    $this->actingAs($stranger)->post('/trade/application/withdraw', ['confirm' => '1'])->assertNotFound();
});

it('takes a reply with documents back to in review, keeps the history and tells the reviewer', function () {
    $applicant = User::factory()->create();
    $application = aplApplication($applicant);
    $pdf = UploadedFile::fake()->create('statement.pdf', 200, 'application/pdf');

    $this->actingAs($applicant)->post('/trade/application/reply', ['message' => 'Statement attached.', 'files' => [$pdf]])
        ->assertRedirect(route('trade.application'));

    $fresh = $application->fresh();
    $attachment = Attachment::query()->where('attachable_type', 'b2b_application')->where('attachable_id', $application->id)->sole();
    $audit = DB::table('audit_log')->where('action', 'application.applicant_replied')->where('subject_id', $application->id)->sole();
    expect($fresh->status)->toBe('in_review')
        ->and($fresh->info_request)->toBe('Please send a recent bank statement.')
        ->and($attachment->is_customer_visible)->toBeFalse()
        ->and($attachment->uploaded_by_user_id)->toBe($applicant->id)
        ->and($audit->reason)->toBe('Statement attached.')
        ->and(DB::table('notification_log')->where('notification_key', 'application.reply_received')->where('user_id', $application->reviewer_user_id)->count())->toBe(1);
    Storage::disk(config('documents.disk'))->assertExists($attachment->path);
});

it('refuses a reply when no information was asked for, and unsafe or oversized files', function () {
    $applicant = User::factory()->create();
    aplApplication($applicant, ['status' => 'in_review']);

    $this->actingAs($applicant)->post('/trade/application/reply', ['message' => 'Unprompted'])->assertForbidden();

    $other = User::factory()->create();
    aplApplication($other);
    $this->actingAs($other)->post('/trade/application/reply', ['message' => 'x', 'files' => [UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')]])
        ->assertSessionHasErrors(['message', 'files.0']);
    $this->actingAs($other)->post('/trade/application/reply', ['message' => 'Big file', 'files' => [UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')]])
        ->assertSessionHasErrors('files.0');
    $this->actingAs($other)->post('/trade/application/reply', ['message' => str_repeat('a', 2001)])->assertSessionHasErrors('message');
    expect(Attachment::query()->count())->toBe(0);
});

it('withdraws an open application after confirmation, and nothing else', function () {
    $applicant = User::factory()->create();
    $application = aplApplication($applicant, ['status' => 'submitted']);

    $this->actingAs($applicant)->post('/trade/application/withdraw', [])->assertSessionHasErrors('confirm');
    expect($application->fresh()->status)->toBe('submitted');

    $this->actingAs($applicant)->post('/trade/application/withdraw', ['confirm' => '1'])->assertRedirect(route('trade.application'));
    expect($application->fresh()->status)->toBe('withdrawn')
        ->and(DB::table('audit_log')->where('action', 'application.withdrawn')->where('subject_id', $application->id)->count())->toBe(1);

    // Already closed: refused.
    $this->actingAs($applicant)->post('/trade/application/withdraw', ['confirm' => '1'])->assertForbidden();
});

it('shows a rejected applicant the message we chose and when they may apply again, never the internal reason', function () {
    $applicant = User::factory()->create();
    aplApplication($applicant, [
        'status' => 'rejected', 'info_request' => null, 'reviewed_at' => now(),
        'applicant_message' => 'We could not verify your trading address.', 'rejection_category' => 'business_not_verified',
        'rejection_remediable' => false, 'reapply_after' => now()->addDays(90),
    ]);

    $response = $this->actingAs($applicant)->get('/trade/application')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('application.applicant_message', 'We could not verify your trading address.')
        ->where('application.rejection.may_reapply_now', false)
        ->where('application.can_reply', false)
        ->where('application.can_withdraw', false));

    expect(json_encode($response->viewData('page')['props']))->not->toContain('business_not_verified')->not->toContain('INTERNAL');
});
