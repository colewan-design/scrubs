<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageStoreSettings;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\TaxRate;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Shipping\StallionProvider;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Live shipping rates from Stallion Express at checkout (§5).
 *
 * The flow under test: the customer's address arrives with the quote, goes to
 * Stallion, and what comes back is reduced to the two services checkout offers
 * — Standard and Express — one of which is then charged and kept on the order.
 *
 * Stallion is faked throughout, with answers in the shape the live service
 * gave on 2026-10-05. These prove what is asked of it and what is made of the
 * answer. The assertions that matter most are about money
 * — the price charged is the price shown, and tax is not charged twice — and
 * about checkout carrying on when Stallion does not answer.
 */
class StallionRatesTest extends TestCase
{
    use RefreshDatabase;

    protected const RATES_URL = 'https://ship.stallion.ca/api/v5/rates';

    protected ?string $cartToken = null;

    protected ?User $shopper = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Named in full so a developer's own .env cannot change what is tested.
        config([
            'services.stallion.key' => 'test-stallion-token',
            'services.stallion.mode' => 'live',
            'services.stallion.base_url' => 'https://ship.stallion.ca/api/v5',
            'services.stallion.timeout' => 10,
            'services.stallion.cache_minutes' => 30,
            'services.stallion.cm3_per_kg' => 5000,
        ]);

        // The provider is only bound when a token exists, and that was decided
        // before the config above was set — so the real wiring is run again
        // rather than a binding being made up here.
        (new AppServiceProvider($this->app))->register();

        $settings = app(Settings::class);
        $settings->set('shipping.provider', 'stallion');
        // Pickup is its own row at checkout and its own test elsewhere.
        $settings->set('pickup.enabled', '0');

        TaxRate::create([
            'province' => 'ON', 'tax_type' => 'HST', 'applies_to' => 'goods',
            'rate_bps' => 1300, 'is_active' => true,
        ]);
        TaxRate::create([
            'province' => 'ON', 'tax_type' => 'HST', 'applies_to' => 'shipping',
            'rate_bps' => 1300, 'is_active' => true,
        ]);

        // The table rates Stallion replaces — and what checkout falls back on.
        $zone = ShippingZone::create([
            'name' => 'Canada', 'provinces' => [], 'position' => 0, 'is_active' => true,
        ]);

        ShippingRate::create([
            'shipping_zone_id' => $zone->id, 'name' => 'Standard', 'rate_cents' => 1500,
            'is_free' => false, 'position' => 0, 'is_active' => true,
        ]);
        ShippingRate::create([
            'shipping_zone_id' => $zone->id, 'name' => 'Express', 'rate_cents' => 2900,
            'is_free' => false, 'position' => 1, 'is_active' => true,
        ]);
    }

    protected function shopper(): User
    {
        return $this->shopper ??= User::factory()->create();
    }

    /** Two tops at $50 and 480 g each, unless the test says otherwise. */
    protected function fillCart(int $qty = 2): void
    {
        $variant = ProductVariant::factory()
            ->for(Product::factory()->state(['retail_price_cents' => 5000]))
            ->create(['stock_qty' => 100, 'reserved_qty' => 0, 'weight_grams' => 480]);

        $response = $this->actingAs($this->shopper())
            ->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'qty' => $qty]);

        $this->cartToken = $response->json('cart_token');
    }

    protected function cartHeaders(): array
    {
        return $this->cartToken ? ['X-Cart-Token' => $this->cartToken] : [];
    }

    /** 5580 Belmont Ave, Niagara Falls — as the address lookup fills it in. */
    protected function destination(): array
    {
        return [
            'line1' => '5580 Belmont Avenue', 'city' => 'Niagara Falls',
            'province' => 'ON', 'postal_code' => 'L2H 1T5',
        ];
    }

    protected function quote(?array $payload = null)
    {
        return $this->actingAs($this->shopper())->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout/quote', $payload ?? $this->destination());
    }

    protected function placeOrder(string $option)
    {
        return $this->actingAs($this->shopper())->withHeaders($this->cartHeaders())
            ->postJson('/api/v1/checkout', [
                'email' => 'dana@clinic.ca',
                'shipping_option' => $option,
                'shipping_address' => $this->destination() + [
                    'first_name' => 'Dana', 'last_name' => 'Reid', 'country' => 'CA',
                ],
            ]);
    }

    /** One rate, in the shape Stallion's v5 `POST /rates` returns. */
    protected function rate(
        string $service,
        string $carrier,
        string $name,
        float $subtotal,
        ?int $days,
        bool $trackable = true,
    ): array {
        $tax = round($subtotal * 0.13, 2);

        return [
            'postage_type_id' => crc32($service) % 1000,
            'service' => $service,
            'carrier' => $carrier,
            'service_name' => $name,
            'add_ons' => [],
            'base_rate' => $subtotal,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'hst' => $tax,
            'total' => round($subtotal + $tax, 2),
            'currency' => 'CAD',
            'estimated_delivery_days' => $days,
            'trackable' => $trackable,
        ];
    }

    /** What a city address really gets back: a long list, in no useful order. */
    protected function lane(): array
    {
        return [
            $this->rate('ups.express_early', 'UPS', 'Express Early', 61.00, 1),
            $this->rate('canada_post.expedited', 'Canada Post', 'Expedited Parcel', 14.10, 4),
            $this->rate('canada_post.lettermail', 'Canada Post', 'Lettermail', 4.20, 5, trackable: false),
            $this->rate('purolator.express', 'Purolator', 'Express', 27.80, 1),
            $this->rate('canada_post.regular', 'Canada Post', 'Regular Parcel', 12.40, 6),
            $this->rate('canada_post.xpresspost', 'Canada Post', 'Xpresspost', 19.90, 2),
        ];
    }

    protected function fakeRates(?array $rates = null): void
    {
        Http::fake(['ship.stallion.ca/*' => Http::response([
            'data' => $rates ?? $this->lane(),
            'meta' => ['timeout_seconds' => 4, 'excluded_services' => []],
        ])]);
    }

    // --------------------------------------------------------- what is sent

    public function test_the_customers_address_is_what_stallion_is_asked_to_rate(): void
    {
        $this->fakeRates();
        $this->fillCart(2);

        $this->quote($this->destination() + ['line2' => 'Unit 4'])->assertOk();

        Http::assertSent(function (Request $request) {
            return $request->url() === self::RATES_URL
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer test-stallion-token')
                && $request['to_address'] === [
                    'name' => 'Customer',
                    'address1' => '5580 Belmont Avenue',
                    'address2' => 'Unit 4',
                    'city' => 'Niagara Falls',
                    'province_code' => 'ON',
                    'postal_code' => 'L2H 1T5',
                    'country_code' => 'CA',
                ]
                // Two tops at 480 g, as one parcel — a mailer sized from that
                // weight, since Stallion will not quote without a size.
                && $request['packages'] == [[
                    'weight' => 0.96, 'weight_unit' => 'kg',
                    'length' => 35, 'width' => 28, 'height' => 4.8, 'size_unit' => 'cm',
                ]]
                // Required too, and declared once for the whole order.
                && $request['items'] == [[
                    'title' => 'Medical scrubs', 'quantity' => 1, 'value' => 100, 'currency' => 'CAD',
                ]]
                // Stallion's cut-off for slow carriers sits inside ours.
                && $request['timeout'] === 7;
        });
    }

    /**
     * A size guessed too large is a price quoted too high, so the parcel is
     * sized from its weight: a mailer while that is a sensible shape, a
     * carton past it. Thirty tops are 14.4 kg, which is 72 litres.
     */
    public function test_a_large_order_is_rated_as_a_carton_not_a_very_tall_mailer(): void
    {
        $this->fakeRates();
        $this->fillCart(30);

        $this->quote()->assertOk();

        Http::assertSent(function (Request $request) {
            $package = $request['packages'][0];

            return $package['weight'] == 14.4
                && $package['length'] == 41.6 && $package['width'] == 41.6 && $package['height'] == 41.6
                && $package['length'] * $package['width'] * $package['height'] <= 72000;
        });
    }

    /**
     * Stallion refuses to rate anything declared above CAD 1,000. Thirty tops
     * are worth more than that, and must still get a live rate.
     */
    public function test_the_declared_value_stays_within_what_stallion_accepts(): void
    {
        $this->fakeRates();
        $this->fillCart(30);

        $this->quote()->assertOk()->assertJsonPath('shipping_options.0.provider', 'stallion');

        Http::assertSent(fn (Request $request) => $request['items'][0]['value'] == 1000);
    }

    /** However the customer typed it, Stallion gets the postal code one way. */
    public function test_the_postal_code_is_sent_in_canada_posts_format(): void
    {
        $this->fakeRates();
        $this->fillCart();

        $this->quote(['postal_code' => 'l2h1t5'] + $this->destination())->assertOk();

        Http::assertSent(fn (Request $request) => $request['to_address']['postal_code'] === 'L2H 1T5');
    }

    // ----------------------------------------------------- what is offered

    public function test_checkout_offers_standard_and_express_and_nothing_else(): void
    {
        $this->fakeRates();
        $this->fillCart();

        $response = $this->quote()->assertOk();

        $response->assertJsonCount(2, 'shipping_options');

        // Standard: the cheapest that can be tracked. Lettermail at $4.20 is
        // cheaper still and is not it.
        $response->assertJsonPath('shipping_options.0.code', 'stallion:standard');
        $response->assertJsonPath('shipping_options.0.name', 'Standard');
        $response->assertJsonPath('shipping_options.0.carrier', 'Canada Post');
        $response->assertJsonPath('shipping_options.0.service', 'Regular Parcel');
        $response->assertJsonPath('shipping_options.0.cost.cents', 1240);
        $response->assertJsonPath('shipping_options.0.delivery_estimate', '6 business days');

        // Express: the fastest. Two services arrive next day; the $27.80 one
        // is it, not the $61 one.
        $response->assertJsonPath('shipping_options.1.code', 'stallion:express');
        $response->assertJsonPath('shipping_options.1.name', 'Express');
        $response->assertJsonPath('shipping_options.1.carrier', 'Purolator');
        $response->assertJsonPath('shipping_options.1.cost.cents', 2780);
        $response->assertJsonPath('shipping_options.1.delivery_estimate', '1 business day');

        // Standard unless the customer says otherwise.
        $response->assertJsonPath('selected_shipping_option', 'stallion:standard');
    }

    /**
     * Stallion quotes $12.40 + $1.61 HST = $14.01. Charging the $14.01 and
     * then taxing shipping ourselves would collect HST on the HST.
     */
    public function test_shipping_is_charged_before_tax_and_taxed_once(): void
    {
        $this->fakeRates();
        $this->fillCart();

        $response = $this->quote()->assertOk();

        $response->assertJsonPath('shipping.cents', 1240);
        // 13% of ($100 goods + $12.40 shipping).
        $response->assertJsonPath('tax.total_cents', 1461);
        $response->assertJsonPath('grand_total.cents', 10000 + 1240 + 1461);
    }

    /** A local courier is often both the cheapest and next-day. */
    public function test_express_is_not_offered_when_nothing_is_faster_than_standard(): void
    {
        $this->fakeRates([
            $this->rate('uniuni.standard', 'UniUni', 'UniUni Standard', 7.95, 1),
            $this->rate('canada_post.expedited', 'Canada Post', 'Expedited Parcel', 14.10, 1),
            $this->rate('canada_post.regular', 'Canada Post', 'Regular Parcel', 12.40, 4),
        ]);
        $this->fillCart();

        $response = $this->quote()->assertOk();

        $response->assertJsonCount(1, 'shipping_options');
        $response->assertJsonPath('shipping_options.0.code', 'stallion:standard');
        $response->assertJsonPath('shipping_options.0.cost.cents', 795);
    }

    /** With no estimate there is no saying a service is the fast one. */
    public function test_a_service_with_no_delivery_estimate_is_never_express(): void
    {
        $this->fakeRates([
            $this->rate('canada_post.regular', 'Canada Post', 'Regular Parcel', 12.40, null),
            $this->rate('pt.32', 'Rivo', 'Rivo Ground', 31.00, null),
            $this->rate('canada_post.xpresspost', 'Canada Post', 'Xpresspost', 19.90, 2),
        ]);
        $this->fillCart();

        $response = $this->quote()->assertOk();

        $response->assertJsonPath('shipping_options.0.service', 'Regular Parcel');
        $response->assertJsonPath('shipping_options.0.delivery_estimate', null);
        $response->assertJsonPath('shipping_options.1.service', 'Xpresspost');
        $response->assertJsonPath('shipping_options.1.delivery_estimate', '2 business days');
    }

    /** Free shipping must use the same threshold the cart advertises. */
    public function test_free_shipping_makes_standard_free_and_leaves_express_paid_for(): void
    {
        $this->fakeRates();
        app(Settings::class)->set('shipping.free_threshold_cents', 60000);
        $this->fillCart(13); // $650

        $options = collect($this->quote()->assertOk()->json('shipping_options'))->keyBy('name');

        $this->assertSame(0, $options['Standard']['cost']['cents']);
        $this->assertTrue($options['Standard']['free_threshold_applied']);
        $this->assertSame(2780, $options['Express']['cost']['cents']);
    }

    // ------------------------------------------------- the order it becomes

    public function test_the_order_charges_the_quoted_rate_and_records_the_service(): void
    {
        $this->fakeRates();
        $this->fillCart();

        $this->quote()->assertOk();

        $response = $this->placeOrder('stallion:express')->assertCreated();

        $response->assertJsonPath('order.totals.shipping.cents', 2780);
        $response->assertJsonPath('order.shipping_method', 'Express');

        // What whoever packs it needs: which label the customer paid for.
        $order = Order::where('order_number', $response->json('order.order_number'))->firstOrFail();

        $this->assertSame('Express', $order->shipping_method);
        $this->assertSame('Purolator', $order->shipping_carrier);
        $this->assertSame('Express', $order->shipping_service);
        $this->assertSame('purolator.express', $order->shipping_service_code);
    }

    /**
     * Checkout asks again every time the customer changes anything, and once
     * more to place the order. One answer serves all of it, which is also
     * what keeps the price charged the same as the price shown.
     */
    public function test_stallion_is_asked_once_for_the_whole_checkout(): void
    {
        $this->fakeRates();
        $this->fillCart();

        $this->quote()->assertOk();
        $this->quote($this->destination() + ['shipping_option' => 'stallion:express'])->assertOk();
        // The street is corrected; the postal code, which is what is priced, is not.
        $this->quote(['line1' => '5582 Belmont Avenue'] + $this->destination())->assertOk();
        $this->placeOrder('stallion:express')->assertCreated();

        Http::assertSentCount(1);
    }

    /** Rule 1 of OrderService holds for carrier rates too. */
    public function test_a_service_that_was_never_quoted_cannot_be_ordered(): void
    {
        $this->fakeRates([$this->rate('uniuni.standard', 'UniUni', 'UniUni Standard', 7.95, 1)]);
        $this->fillCart();

        $this->placeOrder('stallion:express')->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    // ------------------------------------------- when Stallion is not asked

    /** The token alone is not the switch; Store settings is. */
    public function test_a_token_alone_does_not_turn_live_rates_on(): void
    {
        Http::fake();
        app(Settings::class)->set('shipping.provider', 'table_rate');
        $this->fillCart();

        $this->quote()->assertOk()->assertJsonPath('shipping_options.0.provider', 'table_rate');

        Http::assertNothingSent();
    }

    /**
     * Checkout re-quotes as the address is typed. A carrier cannot rate half
     * a postal code or a province with no street, so until the address is
     * whole the rate table answers and Stallion is left alone.
     */
    public function test_an_unfinished_address_is_rated_from_the_table_without_asking_stallion(): void
    {
        Http::fake();
        $this->fillCart();

        foreach ([
            ['province' => 'ON'],
            ['postal_code' => 'L2H 1'] + $this->destination(),
            ['line1' => ''] + $this->destination(),
            ['city' => null] + $this->destination(),
        ] as $unfinished) {
            $this->quote($unfinished)->assertOk()
                ->assertJsonPath('shipping_options.0.provider', 'table_rate')
                ->assertJsonPath('shipping_options.0.cost.cents', 1500);
        }

        Http::assertNothingSent();
    }

    /**
     * One account walking through postal codes would spend Stallion's rate
     * limit for everybody. It is cut off quietly, onto the rate table.
     */
    public function test_one_customer_cannot_send_stallion_an_endless_run_of_addresses(): void
    {
        $this->fakeRates();
        $this->fillCart();

        $allowed = StallionProvider::LOOKUPS_PER_CUSTOMER;

        for ($i = 0; $i < $allowed; $i++) {
            $this->quote(['postal_code' => sprintf('L2H %dT%d', intdiv($i, 10), $i % 10)] + $this->destination())
                ->assertOk()
                ->assertJsonPath('shipping_options.0.provider', 'stallion');
        }

        $this->quote(['postal_code' => 'M5H 2N2'] + $this->destination())
            ->assertOk()
            ->assertJsonPath('shipping_options.0.provider', 'table_rate');

        // An address already answered for is still answered: that costs Stallion nothing.
        $this->quote(['postal_code' => 'L2H 0T0'] + $this->destination())
            ->assertOk()
            ->assertJsonPath('shipping_options.0.provider', 'stallion');

        Http::assertSentCount($allowed);
    }

    // ---------------------------------------- when Stallion does not answer

    /** Rule 2 of ShippingService: a carrier outage is not the customer's problem. */
    public function test_checkout_falls_back_to_table_rates_when_stallion_fails(): void
    {
        Http::fake(['ship.stallion.ca/*' => Http::response([
            'error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated.'],
        ], 401)]);
        $this->fillCart();

        $response = $this->quote()->assertOk();

        $response->assertJsonPath('shipping_options.0.provider', 'table_rate');
        $response->assertJsonPath('shipping_options.0.name', 'Standard');
        $response->assertJsonPath('shipping_options.0.cost.cents', 1500);
        $response->assertJsonPath('shipping_options.1.cost.cents', 2900);
    }

    public function test_checkout_falls_back_to_table_rates_when_stallion_cannot_be_reached(): void
    {
        Http::fake(['ship.stallion.ca/*' => fn () => throw new ConnectionException('Operation timed out.')]);
        $this->fillCart();

        $this->quote()->assertOk()
            ->assertJsonPath('shipping_options.0.provider', 'table_rate')
            ->assertJsonPath('shipping_options.0.cost.cents', 1500);
    }

    /** Every service on the lane untracked, or priced in another currency. */
    public function test_checkout_falls_back_when_stallion_offers_nothing_usable(): void
    {
        $usd = ['currency' => 'USD'] + $this->rate('usps.ground_advantage', 'USPS', 'Ground Advantage', 9.10, 5);

        $this->fakeRates([
            $this->rate('canada_post.lettermail', 'Canada Post', 'Lettermail', 4.20, 5, trackable: false),
            $usd,
        ]);
        $this->fillCart();

        $this->quote()->assertOk()->assertJsonPath('shipping_options.0.provider', 'table_rate');
    }

    /** An outage is over when it is over, not thirty minutes later. */
    public function test_a_failure_is_not_remembered(): void
    {
        Http::fakeSequence('ship.stallion.ca/*')
            ->push(['error' => ['code' => 'internal_error', 'message' => 'Server error.']], 500)
            ->push(['data' => $this->lane(), 'meta' => []]);
        $this->fillCart();

        $this->quote()->assertOk()->assertJsonPath('shipping_options.0.provider', 'table_rate');
        $this->quote()->assertOk()->assertJsonPath('shipping_options.0.provider', 'stallion');
    }

    // ----------------------------------------------------- the admin switch

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    public function test_an_administrator_switches_live_rates_on_in_store_settings(): void
    {
        app(Settings::class)->set('shipping.provider', 'table_rate');

        $this->actingAs($this->admin())->get('/admin/manage-store-settings')
            ->assertOk()
            ->assertSee('Stallion Express — live rates');

        Livewire::actingAs($this->admin())->test(ManageStoreSettings::class)
            ->set('data.shipping_provider', 'stallion')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('stallion', app(Settings::class)->string('shipping.provider'));
    }

    /** A switch that could be thrown with nothing behind it would look like it worked. */
    public function test_live_rates_cannot_be_switched_on_without_a_token(): void
    {
        config(['services.stallion.key' => null]);
        app(Settings::class)->set('shipping.provider', 'table_rate');

        Livewire::actingAs($this->admin())->test(ManageStoreSettings::class)
            ->assertSee('STALLION_API_KEY')
            ->set('data.shipping_provider', 'stallion')
            ->call('save')
            ->assertHasErrors(['data.shipping_provider']);

        $this->assertSame('table_rate', app(Settings::class)->string('shipping.provider'));
    }
}
