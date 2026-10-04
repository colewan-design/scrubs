<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Address\AddressLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Address suggestions for the checkout form.
 *
 * `config` comes first: it tells the storefront which provider is in force, or
 * that none is. With Mapbox that is the whole of this controller's part — the
 * browser takes the public token and talks to Mapbox itself.
 *
 * `suggest` and `resolve` are the Google provider's two halves of a lookup:
 * one while the customer types, one once they pick. Neither can fail in a way
 * the customer sees — a Google outage and "no such street" both come back as
 * an empty answer, and the form is simply typed by hand.
 */
class AddressLookupController extends Controller
{
    /** What the storefront generates per lookup: a UUID, give or take. */
    protected const SESSION = 'regex:/^[A-Za-z0-9_-]{16,36}$/';

    public function __construct(protected AddressLookup $lookup) {}

    public function config(): JsonResponse
    {
        return response()->json($this->lookup->publicConfig());
    }

    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:120'],
            'session' => ['required', 'string', self::SESSION],
        ]);

        return response()->json([
            'suggestions' => $this->lookup->suggest($data['q'], $data['session']),
        ]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Opaque, and Google's to define — but it goes into a URL, so it is
            // held to the characters a place id is actually made of.
            'id' => ['required', 'string', 'max:300', 'regex:/^[A-Za-z0-9_-]+$/'],
            'session' => ['required', 'string', self::SESSION],
            'typed' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'address' => $this->lookup->resolve($data['id'], $data['session'], $data['typed'] ?? null),
        ]);
    }
}
