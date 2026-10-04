<?php

namespace App\Services\Address;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Address suggestions as the customer types.
 *
 * Two providers, one switch (ADDRESS_LOOKUP_PROVIDER), and they work in
 * different places:
 *
 *   mapbox — the storefront talks to Mapbox itself, through Mapbox's own SDK,
 *            because that is the only way Mapbox licenses address autofill.
 *            All this class does for it is hand over the public token.
 *
 *   google — the storefront asks this application, which asks Google's Places
 *            API (New) with a key the browser never sees. `suggest()` and
 *            `resolve()` below are that conversation, and only that one.
 *
 * Either way the storefront asks `publicConfig()` first and does whatever it
 * says, so changing provider is an .env edit and not a frontend build.
 *
 * The Google half is written against Google's published request and response
 * shapes but NOT yet verified against the live API — that needs a key.
 * `suggest()` and `resolve()` are the two places to look first if a real
 * response disagrees.
 *
 * A lookup is two steps, tied together by a session token the storefront makes
 * up: any number of `suggest()` calls while the customer types, then one
 * `resolve()` for the suggestion they pick. Google bills by that pairing, so
 * the token must be the same throughout and must not be reused afterwards.
 *
 * Every failure path returns "nothing found" rather than throwing. The address
 * form works perfectly well typed by hand, and a Google outage must never be
 * able to stop a customer checking out.
 */
class AddressLookup
{
    protected const BASE_URL = 'https://places.googleapis.com/v1';

    /** Below this there is nothing useful to suggest, only requests to pay for. */
    public const MIN_INPUT = 3;

    public const MAPBOX = 'mapbox';

    public const GOOGLE = 'google';

    /**
     * The provider in force, or null when the one chosen has no credential.
     *
     * Null is the normal state of an install nobody has given a key to, not an
     * error: the address form is simply typed by hand.
     */
    public function provider(): ?string
    {
        return match (config('services.address_lookup.provider')) {
            self::MAPBOX => $this->mapboxToken() ? self::MAPBOX : null,
            self::GOOGLE => config('services.address_lookup.google_key') ? self::GOOGLE : null,
            default => null,
        };
    }

    /**
     * What the storefront needs to know to offer suggestions at all.
     *
     * @return array{provider: ?string, mapbox: ?array{token: string}}
     */
    public function publicConfig(): array
    {
        $provider = $this->provider();

        return [
            'provider' => $provider,
            'mapbox' => $provider === self::MAPBOX ? ['token' => $this->mapboxToken()] : null,
        ];
    }

    /**
     * Mapbox's token is used from the browser, so only a public one will do.
     *
     * A secret token (`sk.…`) pasted here by mistake would otherwise be handed
     * to every customer who opens checkout. It is refused, loudly, instead.
     */
    protected function mapboxToken(): ?string
    {
        $token = config('services.address_lookup.mapbox_token');

        if (! $token) {
            return null;
        }

        if (! str_starts_with($token, 'pk.')) {
            Log::warning('MAPBOX_PUBLIC_TOKEN is not a public (pk.) token; address lookup is off.');

            return null;
        }

        return $token;
    }

    /** Whether the proxied lookup below — the Google one — is the one in use. */
    public function enabled(): bool
    {
        return $this->provider() === self::GOOGLE;
    }

    /**
     * Addresses matching what has been typed so far.
     *
     * @return array<int, array{id: string, primary: string, secondary: string}>
     */
    public function suggest(string $input, string $session): array
    {
        $input = trim($input);

        if (! $this->enabled() || mb_strlen($input) < self::MIN_INPUT) {
            return [];
        }

        try {
            $response = $this->client()->post(self::BASE_URL.'/places:autocomplete', [
                'input' => $input,
                'sessionToken' => $session,
                // Canada only — the store ships domestically (§5).
                'includedRegionCodes' => ['ca'],
                // Addresses, not businesses: a shop or a hospital by name is a
                // different question from where a parcel goes.
                'includedPrimaryTypes' => ['street_address', 'premise', 'subpremise', 'route'],
                'languageCode' => 'en',
                'regionCode' => 'ca',
            ]);

            if (! $response->successful()) {
                Log::warning('Address suggestion request failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            return $this->parseSuggestions($response->json('suggestions'));
        } catch (Throwable $e) {
            Log::warning('Address suggestion request threw.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * The picked suggestion, broken into the fields the address form has.
     *
     * A part is null when Google could not say, and null means "ask the
     * customer" — never a guess. Returns null outright when the place cannot
     * be read at all, or is not in Canada.
     *
     * `$typed` is what the customer had in the field when they picked. Google
     * knows plenty of streets it does not know every house number on, and for
     * those the number the customer typed is the only one there is.
     *
     * @return array{line1: ?string, line2: ?string, city: ?string, province: ?string, postal_code: ?string}|null
     */
    public function resolve(string $placeId, string $session, ?string $typed = null): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $response = $this->client()
                // Only what the form needs. The field mask decides the price of
                // this call, and address components are the cheapest tier that
                // still closes the session.
                ->withHeaders(['X-Goog-FieldMask' => 'addressComponents'])
                ->get(self::BASE_URL.'/places/'.rawurlencode($placeId), [
                    'sessionToken' => $session,
                    'languageCode' => 'en',
                    'regionCode' => 'ca',
                ]);

            if (! $response->successful()) {
                Log::warning('Address detail request failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $this->parseAddress($response->json('addressComponents'), $typed);
        } catch (Throwable $e) {
            Log::warning('Address detail request threw.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<int, array{id: string, primary: string, secondary: string}>
     */
    protected function parseSuggestions(mixed $suggestions): array
    {
        if (! is_array($suggestions)) {
            return [];
        }

        $found = [];

        foreach ($suggestions as $suggestion) {
            $prediction = $suggestion['placePrediction'] ?? null;
            $id = $prediction['placeId'] ?? null;
            $primary = $prediction['structuredFormat']['mainText']['text']
                ?? $prediction['text']['text']
                ?? null;

            if (! is_string($id) || $id === '' || ! is_string($primary) || $primary === '') {
                continue;
            }

            $secondary = (string) ($prediction['structuredFormat']['secondaryText']['text'] ?? '');

            $found[] = [
                'id' => $id,
                'primary' => $primary,
                // Every suggestion is in Canada, so saying so on each row is noise.
                'secondary' => (string) preg_replace('/,\s*Canada$/u', '', $secondary),
            ];
        }

        return $found;
    }

    /**
     * @return array{line1: ?string, line2: ?string, city: ?string, province: ?string, postal_code: ?string}|null
     */
    protected function parseAddress(mixed $components, ?string $typed): ?array
    {
        if (! is_array($components)) {
            return null;
        }

        // A component carries several types ("locality" and "political"), and
        // the form asks by type — so index by each, first one wins.
        $parts = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            foreach ((array) ($component['types'] ?? []) as $type) {
                $parts[$type] ??= $component;
            }
        }

        $long = fn (string $type): ?string => $parts[$type]['longText'] ?? $parts[$type]['shortText'] ?? null;
        $short = fn (string $type): ?string => $parts[$type]['shortText'] ?? $parts[$type]['longText'] ?? null;

        if ($short('country') !== 'CA') {
            return null;
        }

        $province = strtoupper((string) $short('administrative_area_level_1'));

        return [
            'line1' => $this->streetLine($long('street_number'), $short('route'), $typed),
            'line2' => $this->unitLine($long('subpremise')),
            // Not every Canadian address has a "locality"; the fallbacks are the
            // next-nearest thing Google calls the town.
            'city' => $long('locality')
                ?? $long('postal_town')
                ?? $long('sublocality_level_1')
                ?? $long('sublocality')
                ?? $long('administrative_area_level_3'),
            'province' => in_array($province, User::PROVINCES, true) ? $province : null,
            'postal_code' => $this->postalCode($long('postal_code')),
        ];
    }

    /** "5580 Belmont Ave" — abbreviated the way Canada Post writes a street. */
    protected function streetLine(?string $number, ?string $street, ?string $typed): ?string
    {
        if (! $street) {
            return null;
        }

        $number ??= $this->typedNumber($typed, $street);

        return trim($number.' '.$street);
    }

    /**
     * The house number the customer typed, for a street Google has no numbers on.
     *
     * Deliberately narrow: digits, optionally a unit prefix ("4-5580") or one
     * trailing letter ("12B"). A street that is itself a number — "5 Ave SW" —
     * is left alone, or it would come back as "5 5 Ave SW".
     */
    protected function typedNumber(?string $typed, string $street): ?string
    {
        if (! $typed || ! preg_match('/^\s*((?:\d+-)?\d+[A-Za-z]?)\s+\S/', $typed, $match)) {
            return null;
        }

        $firstWord = strtok($street, ' ');

        return strcasecmp($match[1], (string) $firstWord) === 0 ? null : $match[1];
    }

    /** A bare unit number reads as a mistake on a label; "Unit 4" does not. */
    protected function unitLine(?string $unit): ?string
    {
        $unit = trim((string) $unit);

        if ($unit === '') {
            return null;
        }

        return preg_match('/^[\w-]+$/', $unit) ? 'Unit '.$unit : $unit;
    }

    /**
     * A complete Canadian postal code, or nothing.
     *
     * For a street with no exact match Google returns only the first three
     * characters. Half a postal code in the box looks finished and is not, so
     * it is dropped and the customer is left to type the whole thing.
     */
    protected function postalCode(?string $code): ?string
    {
        $code = strtoupper((string) preg_replace('/\s+/', '', (string) $code));

        if (! preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $code)) {
            return null;
        }

        return substr($code, 0, 3).' '.substr($code, 3);
    }

    protected function client(): PendingRequest
    {
        return Http::withHeaders(['X-Goog-Api-Key' => config('services.address_lookup.google_key')])
            ->acceptJson()
            // Short: a suggestion that arrives after the customer has typed the
            // next three letters is worth nothing.
            ->timeout(config('services.address_lookup.timeout', 4));
    }
}
