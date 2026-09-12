<script setup lang="ts">
import type { AddressInput } from '~/composables/useApi'

/**
 * The eight address fields, used twice on checkout: once for shipping, and
 * again for billing when it differs.
 *
 * The parent owns the object and this component writes into it. That is
 * deliberate rather than a v-model per field — checkout already holds the
 * address as one reactive object because that is exactly what it posts, and
 * splitting it into eight models here would only reassemble it there.
 *
 * Field order and autocomplete tokens match AccountAddressForm exactly, so a
 * browser's saved-address autofill behaves the same in both places.
 *
 * No phone field: checkout already asks for one in the contact section, and the
 * courier's copy is filled from there rather than asking the same question
 * twice on one screen.
 */
const props = defineProps<{
  address: AddressInput
  errors: Record<string, string[]>
  /** How the API names this address in its validation errors. */
  prefix: 'shipping_address' | 'billing_address'
  /** Autocomplete section, so a browser keeps billing and shipping apart. */
  scope: 'shipping' | 'billing'
}>()

const PROVINCES = [
  ['AB', 'Alberta'], ['BC', 'British Columbia'], ['MB', 'Manitoba'],
  ['NB', 'New Brunswick'], ['NL', 'Newfoundland and Labrador'],
  ['NS', 'Nova Scotia'], ['NT', 'Northwest Territories'], ['NU', 'Nunavut'],
  ['ON', 'Ontario'], ['PE', 'Prince Edward Island'], ['QC', 'Quebec'],
  ['SK', 'Saskatchewan'], ['YT', 'Yukon'],
] as const

const err = (field: string) => props.errors[`${props.prefix}.${field}`]?.[0]
const auto = (token: string) => `${props.scope} ${token}`
</script>

<template>
  <div class="grid gap-3 sm:grid-cols-2">
    <CheckoutField
      v-model="address.first_name"
      label="First name"
      :autocomplete="auto('given-name')"
      :error="err('first_name')"
    />
    <CheckoutField
      v-model="address.last_name"
      label="Last name"
      :autocomplete="auto('family-name')"
      :error="err('last_name')"
    />
    <CheckoutField
      v-model="address.company"
      label="Company"
      optional
      :autocomplete="auto('organization')"
      class="sm:col-span-2"
    />
    <CheckoutField
      v-model="address.line1"
      label="Address"
      :autocomplete="auto('address-line1')"
      :error="err('line1')"
      class="sm:col-span-2"
    />
    <CheckoutField
      v-model="address.line2"
      label="Apartment, suite, etc."
      optional
      :autocomplete="auto('address-line2')"
      class="sm:col-span-2"
    />
    <CheckoutField
      v-model="address.city"
      label="City"
      :autocomplete="auto('address-level2')"
      :error="err('city')"
    />

    <CheckoutField label="Province" :error="err('province')">
      <template #default="{ id, control }">
        <select
          :id="id"
          v-model="address.province"
          :autocomplete="auto('address-level1')"
          :class="control"
        >
          <option value="" disabled>Select a province</option>
          <option v-for="[code, name] in PROVINCES" :key="code" :value="code">{{ name }}</option>
        </select>
      </template>
    </CheckoutField>

    <CheckoutField
      v-model="address.postal_code"
      label="Postal code"
      uppercase
      :autocomplete="auto('postal-code')"
      :error="err('postal_code')"
    />
  </div>
</template>
