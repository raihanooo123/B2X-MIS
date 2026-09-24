<?php

use App\Domain\Billing\CardIntent;
use App\Domain\Billing\Exceptions\PaymentGatewayException;
use App\Domain\Billing\PaymentAllocationService;
use App\Domain\Billing\PaymentGateway;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\CreditHold;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\Pack;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Sku;
use App\Models\StockAllocation;
use App\Models\StockLevel;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * 07 §6.4 card payments: authorised before the order, captured after
 * commit (04 §4.4); a decline commits nothing; the webhook is idempotent
 * on payments_gateway_reference_uq; payments hold brand and last four
 * only; allocation waits for an invoice.
 *
 * Stripe is replaced by FakeCardGateway — no network, and the tests can
 * say what the card did.
 */
final class FakeCardGateway implements PaymentGateway
{
    /** @var array<string, CardIntent> */
    public array $intents = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<string> */
    public array $captured = [];

    public int $created = 0;

    public bool $failCapture = false;

    public function createAuthorisation(int $amountMinor, string $currency, array $metadata, string $idempotencyKey): CardIntent
    {
        $this->created++;
        $id = 'pi_'.Str::random(20);

        return $this->intents[$id] = new CardIntent($id, $id.'_secret_'.Str::random(8), 'requires_payment_method', $amountMinor, $currency, $metadata);
    }

    public function retrieve(string $intentId): CardIntent
    {
        return $this->intents[$intentId] ?? throw new PaymentGatewayException("No such intent {$intentId}");
    }

    public function capture(string $intentId): CardIntent
    {
        if ($this->failCapture) {
            throw new PaymentGatewayException('Capture window expired');
        }
        $this->captured[] = $intentId;

        return $this->set($intentId, 'succeeded');
    }

    public function cancel(string $intentId): void
    {
        $this->cancelled[] = $intentId;
        $this->set($intentId, 'canceled');
    }

    /** What Stripe Elements does when the card is accepted. */
    public function authorise(string $intentId, string $brand = 'visa', string $last4 = '4242'): void
    {
        $this->set($intentId, 'requires_capture', $brand, $last4);
    }

    /** What Stripe Elements leaves behind when the card is declined. */
    public function decline(string $intentId, string $code): void
    {
        $this->set($intentId, 'requires_payment_method', declineCode: $code);
    }

    private function set(string $id, string $status, ?string $brand = null, ?string $last4 = null, ?string $declineCode = null): CardIntent
    {
        $i = $this->retrieve($id);

        return $this->intents[$id] = new CardIntent($i->id, $i->clientSecret, $status, $i->amountMinor, $i->currency, $i->metadata, $brand ?? $i->cardBrand, $last4 ?? $i->cardLast4, $declineCode);
    }
}

beforeEach(function () {
    config([
        'services.stripe.key' => 'pk_test_placeholder',
        'services.stripe.secret' => 'sk_test_placeholder',
        'services.stripe.webhook_secret' => 'whsec_test_placeholder',
    ]);
    $this->gateway = new FakeCardGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);

    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
    $this->location = Location::factory()->default()->create();
    $baseList = PriceList::factory()->create(['scope' => 'base']);
    $taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($taxClass)->create(['country_code' => 'GB', 'rate_bp' => 2000]);

    $this->sku = Sku::factory()->create(['tax_class_id' => $taxClass->id]);
    Pack::factory()->for($this->sku)->create(['base_units' => 1]);
    $this->price = PriceListItem::factory()->for($baseList, 'priceList')->for($this->sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 12345]);
    StockLevel::factory()->for($this->sku)->for($this->location)->create(['on_hand_base_qty' => 100, 'allocated_base_qty' => 0]);
});

function cardTradeBuyer(): User
{
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 10_000_000]);
    $user = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);
    Address::factory()->default()->delivery()->create(['company_id' => $company->id, 'country_code' => 'GB']);

    return $user;
}

/** Cart of 3, previewed; returns the total the buyer sees. */
function cardFillCart(User $user): int
{
    test()->actingAs($user)->postJson('/api/v1/cart/lines', ['sku_id' => test()->sku->public_id, 'pack_qty' => 3])->assertSuccessful();

    return (int) test()->actingAs($user)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB'])->json('total_gross_minor');
}

function cardIntentFor(User $user, int $total): string
{
    return (string) test()->actingAs($user)
        ->postJson('/api/v1/checkout/card-intent', ['expected_total_gross_minor' => $total, 'delivery_country_code' => 'GB'])
        ->assertOk()
        ->json('data.id');
}

/** @return array<string, mixed> */
function cardOrderBody(int $total, ?string $intentId, string $method = 'card'): array
{
    return [
        'payment_method' => $method,
        'expected_total_gross_minor' => $total,
        'payment_intent_id' => $intentId,
        'delivery_address' => ['contact_name' => 'Sam Lee', 'line1' => '1 High Street', 'city' => 'London', 'postcode' => 'E1 6AN', 'country_code' => 'GB'],
    ];
}

function cardPlace(User $user, int $total, ?string $intentId, string $key = '')
{
    return test()->actingAs($user)
        ->withHeader('Idempotency-Key', $key !== '' ? $key : (string) Str::ulid())
        ->postJson('/api/v1/checkout', cardOrderBody($total, $intentId));
}

function stripeWebhook(string $type, array $object): TestResponse
{
    $payload = (string) json_encode(['id' => 'evt_'.Str::random(12), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_placeholder');

    return test()->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], $payload);
}

it('authorises for the previewed total and reuses that authorisation on retry', function () {
    $user = cardTradeBuyer();
    $total = cardFillCart($user);

    $first = cardIntentFor($user, $total);
    $second = cardIntentFor($user, $total);

    expect($second)->toBe($first)
        ->and($this->gateway->created)->toBe(1)
        ->and($this->gateway->intents[$first]->amountMinor)->toBe($total)
        ->and($this->gateway->intents[$first]->metadata['user_id'])->toBe($user->public_id);
});

it('places a card order, captures after commit and stores brand and last four only', function () {
    $user = cardTradeBuyer();
    $total = cardFillCart($user);
    $intent = cardIntentFor($user, $total);
    $this->gateway->authorise($intent, 'visa', '4242');

    cardPlace($user, $total, $intent)->assertCreated()->assertJsonPath('data.payment_status', 'paid');

    $order = Order::query()->sole();
    $payment = Payment::query()->sole();

    expect($order->payment_status)->toBe('paid')
        ->and($order->payment_method)->toBe('card')
        ->and($payment->status)->toBe('captured')
        ->and($payment->gateway)->toBe('stripe')
        ->and($payment->gateway_reference)->toBe($intent)
        ->and($payment->amount_minor)->toBe($total)
        ->and($payment->card_brand)->toBe('visa')
        ->and($payment->card_last4)->toBe('4242')
        ->and($payment->order_id)->toBe($order->id)
        ->and($payment->company_id)->toBe($order->company_id)
        ->and($this->gateway->captured)->toBe([$intent])
        // Sits unallocated until an invoice exists.
        ->and(PaymentAllocation::query()->count())->toBe(0);
});

it('commits nothing when the card is declined, and says why', function () {
    $user = cardTradeBuyer();
    $total = cardFillCart($user);
    $intent = cardIntentFor($user, $total);
    $this->gateway->decline($intent, 'insufficient_funds');

    cardPlace($user, $total, $intent)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'card_declined')
        ->assertJsonPath('error.message', 'Your card was declined for insufficient funds. Try another card, or pay by bank transfer.');

    expect(Order::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(StockAllocation::query()->count())->toBe(0)
        ->and(CreditHold::query()->count())->toBe(0)
        ->and(Cart::query()->sole()->lines()->count())->toBe(1);
});

it('releases the authorisation and commits nothing when the price moves after authorising', function () {
    $user = cardTradeBuyer();
    $total = cardFillCart($user);
    $intent = cardIntentFor($user, $total);
    $this->gateway->authorise($intent);

    $this->price->update(['unit_price_e4' => 13000]);

    cardPlace($user, $total, $intent)->assertStatus(409)->assertJsonPath('error.code', 'price_changed');

    expect($this->gateway->cancelled)->toBe([$intent])
        ->and($this->gateway->captured)->toBe([])
        ->and(Order::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(StockAllocation::query()->count())->toBe(0);
});

it('never places two orders, or charges twice, on one authorisation', function () {
    $user = cardTradeBuyer();
    $total = cardFillCart($user);
    $intent = cardIntentFor($user, $total);
    $this->gateway->authorise($intent);

    $key = (string) Str::ulid();
    $first = cardPlace($user, $total, $intent, $key)->assertCreated();
    // The same request again (a retry after a lost response) replays.
    expect(cardPlace($user, $total, $intent, $key)->assertCreated()->json())->toBe($first->json());

    // A new request reusing the intent is refused — and must not void it.
    cardPlace($user, $total, $intent)->assertStatus(409)->assertJsonPath('error.code', 'payment_already_used');

    expect(Order::query()->count())->toBe(1)
        ->and($this->gateway->captured)->toBe([$intent])
        ->and($this->gateway->cancelled)->toBe([]);
});

it("refuses another buyer's authorisation", function () {
    $owner = cardTradeBuyer();
    $ownerTotal = cardFillCart($owner);
    $intent = cardIntentFor($owner, $ownerTotal);
    $this->gateway->authorise($intent);

    $thief = cardTradeBuyer();
    $total = cardFillCart($thief);

    cardPlace($thief, $total, $intent)->assertUnprocessable()->assertJsonPath('error.code', 'payment_not_authorised');
    expect(Order::query()->count())->toBe(0);
});

it('records a public customer\'s card payment against the order, with no company (02 §19)', function () {
    $user = User::factory()->create();
    $total = cardFillCart($user);
    $intent = cardIntentFor($user, $total);
    $this->gateway->authorise($intent, 'mastercard', '4444');

    cardPlace($user, $total, $intent)->assertCreated();

    $payment = Payment::query()->sole();
    expect($payment->company_id)->toBeNull()
        ->and($payment->order_id)->toBe(Order::query()->sole()->id)
        ->and($payment->card_brand)->toBe('mastercard');
});

it('keeps the order, unpaid, when capture fails after commit — and the webhook completes it once', function () {
    $user = cardTradeBuyer();
    $total = cardFillCart($user);
    $intent = cardIntentFor($user, $total);
    $this->gateway->authorise($intent);
    $this->gateway->failCapture = true;

    cardPlace($user, $total, $intent)->assertCreated()->assertJsonPath('data.payment_status', 'unpaid');
    expect(Payment::query()->sole()->status)->toBe('authorized')
        ->and(Payment::query()->sole()->failure_reason)->toBe('Capture window expired');

    $event = ['id' => $intent, 'object' => 'payment_intent', 'amount' => $total, 'currency' => 'gbp', 'status' => 'succeeded', 'metadata' => []];
    stripeWebhook('payment_intent.succeeded', $event)->assertOk();
    stripeWebhook('payment_intent.succeeded', $event)->assertOk();

    expect(Payment::query()->count())->toBe(1)
        ->and(Payment::query()->sole()->status)->toBe('captured')
        ->and(Payment::query()->sole()->card_last4)->toBe('4242')
        ->and(Order::query()->sole()->payment_status)->toBe('paid');
});

it('rejects unsigned webhooks and acknowledges unknown intents', function () {
    $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad'], '{"type":"payment_intent.succeeded"}')
        ->assertStatus(400);

    stripeWebhook('payment_intent.succeeded', ['id' => 'pi_unknown12345', 'object' => 'payment_intent', 'amount' => 100, 'currency' => 'gbp', 'status' => 'succeeded', 'metadata' => []])
        ->assertOk();

    expect(Payment::query()->count())->toBe(0);
});

it('applies a captured payment to its invoice once one is issued, once', function () {
    $user = cardTradeBuyer();
    $total = cardFillCart($user);
    $intent = cardIntentFor($user, $total);
    $this->gateway->authorise($intent);
    cardPlace($user, $total, $intent)->assertCreated();
    $order = Order::query()->sole();

    $invoice = Invoice::factory()->create(['company_id' => $order->company_id, 'order_id' => $order->id, 'total_gross_minor' => $total]);
    $service = new PaymentAllocationService;
    $service->allocateInvoice($invoice->id);
    $service->allocateInvoice($invoice->id);
    $service->allocatePayment(Payment::query()->sole()->id);

    expect(PaymentAllocation::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->sole()->amount_minor)->toBe($total)
        ->and($invoice->fresh()->paid_minor)->toBe($total)
        ->and($invoice->fresh()->status)->toBe('paid');
});

it('leaves BACS orders unpaid with no payment row', function () {
    $user = cardTradeBuyer();
    $total = cardFillCart($user);

    test()->actingAs($user)->withHeader('Idempotency-Key', (string) Str::ulid())
        ->postJson('/api/v1/checkout', cardOrderBody($total, null, 'bacs'))
        ->assertCreated();

    expect(Order::query()->sole()->payment_status)->toBe('unpaid')
        ->and(Payment::query()->count())->toBe(0);
});

it('does not offer card when Stripe is not configured', function () {
    config(['services.stripe.key' => null, 'services.stripe.secret' => null]);
    $this->withoutVite();

    $methods = $this->actingAs(cardTradeBuyer())->get('/checkout')->assertOk()->viewData('page')['props']['payment_methods'];

    expect(array_column($methods, 'value'))->toBe(['on_account', 'bacs']);
});
