<?php

namespace App\Http\Resources;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved address from the customer's address book (§8).
 *
 * `lines` and `name` are pre-composed here rather than in the storefront so the
 * address book and the order confirmation render an address identically —
 * OrderResource already does the same for the addresses frozen onto an order.
 *
 * @property-read Address $resource
 */
class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $address = $this->resource;

        return [
            'id' => $address->id,
            'label' => $address->label,
            'first_name' => $address->first_name,
            'last_name' => $address->last_name,
            'company' => $address->company,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'province' => $address->province,
            'postal_code' => $address->postal_code,
            'country' => $address->country,
            'phone' => $address->phone,
            'is_default_shipping' => $address->is_default_shipping,
            'is_default_billing' => $address->is_default_billing,

            'name' => trim("{$address->first_name} {$address->last_name}"),
            'lines' => array_values(array_filter([
                $address->company,
                $address->line1,
                $address->line2,
                trim("{$address->city}, {$address->province}  {$address->postal_code}"),
                $address->country === 'CA' ? null : $address->country,
            ])),
        ];
    }
}
