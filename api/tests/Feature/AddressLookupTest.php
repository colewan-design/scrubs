<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Address suggestions at checkout.
 *
 * Two things are covered: which provider the storefront is told to use, and
 * the Google lookup this application proxies. Mapbox's own lookup happens in
 * the browser and has nothing here to test beyond the token it is handed.
 *
 * Google is faked throughout — these prove what is asked of it and what is
 * made of the answer, not that the answer has the shape assumed here. The
 * assertions that matter most are the ones about what is NOT filled in: a
 * guessed postal code or a dropped house number is an order that goes astray.
 */
class AddressLookupTest extends TestCase
{
    use RefreshDatabase;

    protected const SESSION = '0b0e7f0a-6d1c-4c58-9f0e-2a1d0c3b4e5f';

    protected function setUp(): void
    {
        parent::setUp();

        // Named in full so a developer's own .env cannot change what is tested.
        // Most of this file is about the proxied (Google) lookup; the tests
        // about choosing a provider set their own.
        $this->useProvider('google', googleKey: 'test-places-key');
    }

    protected function useProvider(?string $provider, ?string $googleKey = null, ?string $mapboxToken = null): void
    {
        config([
            'services.address_lookup.provider' => $provider,
            'services.address_lookup.google_key' => $googleKey,
            'services.address_lookup.mapbox_token' => $mapboxToken,
        ]);
    }

    protected function lookupConfig()
    {
        return $this->actingAs(User::factory()->create())->getJson('/api/v1/address/lookup');
    }

    protected function suggest(string $q = '5580 Bel')
    {
        return $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/address/suggest?'.http_build_query(['q' => $q, 'session' => self::SESSION]));
    }

    protected function resolve(?string $typed = null)
    {
        return $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/address/resolve?'.http_build_query(array_filter([
                'id' => 'ChIJ-place_1', 'session' => self::SESSION, 'typed' => $typed,
            ])));
    }

    /** @param  array<int, array{0: string, 1: string, 2: array<int, string>}>  $components */
    protected function fakeDetails(array $components): void
    {
        Http::fake(['places.googleapis.com/v1/places/*' => Http::response([
            'addressComponents' => array_map(fn ($c) => [
                'longText' => $c[0], 'shortText' => $c[1], 'types' => $c[2], 'languageCode' => 'en',
            ], $components),
        ])]);
    }

    /** 5580 Belmont Ave, Niagara Falls — the shape of a house Google knows exactly. */
    protected function belmont(): array
    {
        return [
            ['5580', '5580', ['street_number']],
            ['Belmont Avenue', 'Belmont Ave', ['route']],
            ['Niagara Falls', 'Niagara Falls', ['locality', 'political']],
            ['Regional Municipality of Niagara', 'Niagara', ['administrative_area_level_2', 'political']],
            ['Ontario', 'ON', ['administrative_area_level_1', 'political']],
            ['Canada', 'CA', ['country', 'political']],
            ['L2H 1T5', 'L2H 1T5', ['postal_code']],
        ];
    }

    // --------------------------------------------------------------- access

    public function test_address_lookup_needs_a_signed_in_customer(): void
    {
        Http::fake();

        $this->getJson('/api/v1/address/lookup')->assertUnauthorized();
        $this->getJson('/api/v1/address/suggest?q=5580+Bel&session='.self::SESSION)->assertUnauthorized();
        $this->getJson('/api/v1/address/resolve?id=ChIJ-place_1&session='.self::SESSION)->assertUnauthorized();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- provider

    /** No credential is the normal state until one is supplied, not an error. */
    public function test_without_a_credential_the_lookup_is_off_and_calls_nobody(): void
    {
        Http::fake();

        foreach (['google', 'mapbox', 'something-else', null] as $provider) {
            $this->useProvider($provider);

            $this->lookupConfig()->assertOk()->assertExactJson(['provider' => null, 'mapbox' => null]);
            $this->suggest()->assertOk()->assertExactJson(['suggestions' => []]);
            $this->resolve()->assertOk()->assertExactJson(['address' => null]);
        }

        Http::assertNothingSent();
    }

    /** Mapbox is used from the browser, so the storefront is given the token. */
    public function test_mapbox_hands_the_storefront_its_public_token(): void
    {
        $this->useProvider('mapbox', mapboxToken: 'pk.test-public-token');

        $this->lookupConfig()->assertOk()->assertExactJson([
            'provider' => 'mapbox',
            'mapbox' => ['token' => 'pk.test-public-token'],
        ]);
    }

    /** A secret token in the wrong variable must never reach a browser. */
    public function test_a_secret_mapbox_token_is_refused_rather_than_published(): void
    {
        $this->useProvider('mapbox', mapboxToken: 'sk.this-must-stay-on-the-server');

        $this->lookupConfig()->assertOk()
            ->assertExactJson(['provider' => null, 'mapbox' => null])
            ->assertDontSee('sk.this-must-stay-on-the-server');
    }

    /** Google's key stays here; the storefront is told only to ask this API. */
    public function test_google_is_announced_without_its_key(): void
    {
        $this->lookupConfig()->assertOk()
            ->assertExactJson(['provider' => 'google', 'mapbox' => null])
            ->assertDontSee('test-places-key');
    }

    /** One provider at a time: a leftover Google key is not used while Mapbox is chosen. */
    public function test_the_proxied_lookup_is_idle_while_mapbox_is_the_provider(): void
    {
        Http::fake();
        $this->useProvider('mapbox', googleKey: 'test-places-key', mapboxToken: 'pk.test-public-token');

        $this->suggest()->assertOk()->assertExactJson(['suggestions' => []]);
        $this->resolve()->assertOk()->assertExactJson(['address' => null]);

        Http::assertNothingSent();
    }

    /** Each call is billed, so one account cannot be turned into a loop. */
    public function test_lookups_are_rate_limited_per_customer(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $url = '/api/v1/address/suggest?q=55&session='.self::SESSION;

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($user)->getJson($url)->assertOk();
        }

        $this->actingAs($user)->getJson($url)->assertStatus(429);

        // Somebody else's typing is unaffected.
        $this->actingAs(User::factory()->create())->getJson($url)->assertOk();
    }

    // ---------------------------------------------------------- suggestions

    public function test_suggestions_are_asked_for_in_canada_only_and_returned_ready_to_draw(): void
    {
        Http::fake(['places.googleapis.com/v1/places:autocomplete' => Http::response([
            'suggestions' => [
                ['placePrediction' => [
                    'placeId' => 'ChIJ-place_1',
                    'text' => ['text' => '5580 Belmont Avenue, Niagara Falls, ON, Canada'],
                    'structuredFormat' => [
                        'mainText' => ['text' => '5580 Belmont Avenue'],
                        'secondaryText' => ['text' => 'Niagara Falls, ON, Canada'],
                    ],
                ]],
                // Not a place the customer can pick, so it is not offered.
                ['queryPrediction' => ['text' => ['text' => 'belmont avenue pharmacies']]],
            ],
        ])]);

        $this->suggest('5580 Bel')
            ->assertOk()
            ->assertExactJson([
                'suggestions' => [[
                    'id' => 'ChIJ-place_1',
                    'primary' => '5580 Belmont Avenue',
                    'secondary' => 'Niagara Falls, ON',
                ]],
            ]);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('X-Goog-Api-Key', 'test-places-key')
            && $request['input'] === '5580 Bel'
            && $request['sessionToken'] === self::SESSION
            && $request['includedRegionCodes'] === ['ca']);
    }

    /** Two letters match half the country; asking would only cost money. */
    public function test_input_too_short_to_mean_anything_is_not_sent_to_google(): void
    {
        Http::fake();

        $this->suggest('55')->assertOk()->assertJsonPath('suggestions', []);

        Http::assertNothingSent();
    }

    /** A Google outage must look like "no suggestions", never like an error. */
    public function test_a_failing_provider_degrades_to_no_suggestions(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403)]);

        $this->suggest()->assertOk()->assertExactJson(['suggestions' => []]);
        $this->resolve()->assertOk()->assertExactJson(['address' => null]);
    }

    // ------------------------------------------------------------ resolving

    public function test_a_picked_suggestion_fills_street_city_province_and_postal_code(): void
    {
        $this->fakeDetails($this->belmont());

        $this->resolve('5580 Bel')
            ->assertOk()
            ->assertExactJson(['address' => [
                'line1' => '5580 Belmont Ave',
                'line2' => null,
                'city' => 'Niagara Falls',
                'province' => 'ON',
                'postal_code' => 'L2H 1T5',
            ]]);

        // Address components only, in the session the suggestions were made in:
        // both decide what Google charges for the lookup.
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/v1/places/ChIJ-place_1?')
            && str_contains($request->url(), 'sessionToken='.self::SESSION)
            && $request->hasHeader('X-Goog-FieldMask', 'addressComponents')
            && $request->hasHeader('X-Goog-Api-Key', 'test-places-key'));
    }

    public function test_a_unit_goes_to_the_second_address_line(): void
    {
        $this->fakeDetails([['204', '204', ['subpremise']], ...$this->belmont()]);

        $this->resolve()->assertOk()
            ->assertJsonPath('address.line1', '5580 Belmont Ave')
            ->assertJsonPath('address.line2', 'Unit 204');
    }

    /**
     * Google knows the street but not the house. The number the customer typed
     * must survive the pick, and a partial postal code must not be passed off
     * as a whole one.
     */
    public function test_a_street_without_house_numbers_keeps_the_number_the_customer_typed(): void
    {
        $this->fakeDetails([
            ['Belmont Avenue', 'Belmont Ave', ['route']],
            ['Niagara Falls', 'Niagara Falls', ['locality', 'political']],
            ['Ontario', 'ON', ['administrative_area_level_1', 'political']],
            ['Canada', 'CA', ['country', 'political']],
            ['L2H', 'L2H', ['postal_code', 'postal_code_prefix']],
        ]);

        $this->resolve('5580 belmont')
            ->assertOk()
            ->assertExactJson(['address' => [
                'line1' => '5580 Belmont Ave',
                'line2' => null,
                'city' => 'Niagara Falls',
                'province' => 'ON',
                'postal_code' => null,
            ]]);
    }

    /** "5 Ave SW" is a street, not house number 5 on "Ave SW". */
    public function test_a_numbered_street_is_not_mistaken_for_a_house_number(): void
    {
        $this->fakeDetails([
            ['5 Avenue Southwest', '5 Ave SW', ['route']],
            ['Calgary', 'Calgary', ['locality', 'political']],
            ['Alberta', 'AB', ['administrative_area_level_1', 'political']],
            ['Canada', 'CA', ['country', 'political']],
        ]);

        $this->resolve('5 ave sw')->assertOk()->assertJsonPath('address.line1', '5 Ave SW');
        $this->resolve('700 5 ave sw')->assertOk()->assertJsonPath('address.line1', '700 5 Ave SW');
    }

    /** The store ships within Canada only (§5). */
    public function test_a_place_outside_canada_fills_nothing(): void
    {
        $this->fakeDetails([
            ['350', '350', ['street_number']],
            ['5th Avenue', '5th Ave', ['route']],
            ['New York', 'New York', ['locality', 'political']],
            ['New York', 'NY', ['administrative_area_level_1', 'political']],
            ['United States', 'US', ['country', 'political']],
            ['10118', '10118', ['postal_code']],
        ]);

        $this->resolve()->assertOk()->assertExactJson(['address' => null]);
    }

    // ----------------------------------------------------------- validation

    public function test_malformed_lookups_are_refused_before_reaching_google(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/address/suggest?q=5580+Bel')
            ->assertStatus(422)->assertJsonValidationErrors('session');

        $this->actingAs($user)->getJson('/api/v1/address/resolve?id=../../secret&session='.self::SESSION)
            ->assertStatus(422)->assertJsonValidationErrors('id');

        Http::assertNothingSent();
    }
}
