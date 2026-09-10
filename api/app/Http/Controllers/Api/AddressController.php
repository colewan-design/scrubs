<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The customer's saved address book — §8.
 *
 * Every query is scoped through the signed-in user's own relation rather than
 * Address::find(), so there is no route where a guessed id reaches somebody
 * else's address. That is worth more than a policy class here: the scoping is
 * impossible to forget because it is the only way the record is fetched.
 *
 * These are addresses the customer keeps, not the addresses on an order. An
 * order freezes its own copy (order_addresses) at checkout, so editing an entry
 * here never rewrites the history of an order already placed.
 */
class AddressController extends Controller
{
    /** Enough for a clinic with several sites; low enough to not be a dumping ground. */
    protected const LIMIT = 20;

    public function index(Request $request): AnonymousResourceCollection
    {
        return AddressResource::collection($this->book($request));
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->user()->addresses()->count() >= self::LIMIT) {
            throw ValidationException::withMessages([
                'label' => 'You have reached the maximum of '.self::LIMIT.' saved addresses. Delete one first.',
            ]);
        }

        $data = $this->validated($request);

        // The first address saved is the default whether or not it was ticked —
        // an address book with no default makes checkout prefill nothing.
        $first = $request->user()->addresses()->doesntExist();

        $address = DB::transaction(function () use ($request, $data, $first): Address {
            // Spread, not `+`: array union keeps the left operand's value for a
            // duplicate key, so `$data + [...]` would silently drop both of
            // these overrides — $data already carries the un-defaulted flags.
            $address = $request->user()->addresses()->create([
                ...$data,
                'is_default_shipping' => $data['is_default_shipping'] || $first,
                'is_default_billing' => $data['is_default_billing'] || $first,
            ]);

            $this->enforceSingleDefault($request, $address);

            return $address;
        });

        return response()->json(['address' => new AddressResource($address->fresh())], 201);
    }

    public function update(Request $request, int $address): JsonResponse
    {
        $record = $this->find($request, $address);
        $data = $this->validated($request);

        DB::transaction(function () use ($request, $record, $data): void {
            $record->update($data);
            $this->enforceSingleDefault($request, $record);
        });

        return response()->json(['address' => new AddressResource($record->fresh())]);
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $record = $this->find($request, $address);

        DB::transaction(function () use ($request, $record): void {
            $wasDefaultShipping = $record->is_default_shipping;
            $wasDefaultBilling = $record->is_default_billing;

            $record->delete();

            // Deleting the default must not leave the book without one, or
            // checkout silently stops prefilling.
            $successor = $request->user()->addresses()->oldest('id')->first();

            if ($successor) {
                $successor->forceFill(array_filter([
                    'is_default_shipping' => $wasDefaultShipping ?: null,
                    'is_default_billing' => $wasDefaultBilling ?: null,
                ]))->save();
            }
        });

        return response()->json(['message' => 'Address deleted.']);
    }

    /** @return Collection<int, Address> */
    protected function book(Request $request)
    {
        return $request->user()->addresses()
            ->orderByDesc('is_default_shipping')
            ->orderBy('id')
            ->get();
    }

    protected function find(Request $request, int $id): Address
    {
        return $request->user()->addresses()->findOrFail($id);
    }

    /**
     * Exactly one default of each kind. Done as a sweep of the siblings rather
     * than a check on the incoming record, so the invariant holds even if data
     * arrives from somewhere else (a seeder, an admin edit, an import).
     */
    protected function enforceSingleDefault(Request $request, Address $address): void
    {
        foreach (['is_default_shipping', 'is_default_billing'] as $flag) {
            if (! $address->{$flag}) {
                continue;
            }

            $request->user()->addresses()
                ->whereKeyNot($address->id)
                ->where($flag, true)
                ->update([$flag => false]);
        }
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:40'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'company' => ['nullable', 'string', 'max:120'],
            'line1' => ['required', 'string', 'max:160'],
            'line2' => ['nullable', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:80'],
            'province' => ['required', 'string', 'size:2'],
            // Canadian format, forgiving about the space. Kept loose enough
            // that a US postal code still saves — the tax engine keys off the
            // province, not this.
            'postal_code' => ['required', 'string', 'max:10'],
            'country' => ['nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_default_shipping' => ['nullable', 'boolean'],
            'is_default_billing' => ['nullable', 'boolean'],
        ]);

        return [
            ...$data,
            'province' => strtoupper($data['province']),
            'postal_code' => strtoupper($data['postal_code']),
            'country' => strtoupper($data['country'] ?? 'CA'),
            'is_default_shipping' => (bool) ($data['is_default_shipping'] ?? false),
            'is_default_billing' => (bool) ($data['is_default_billing'] ?? false),
        ];
    }
}
