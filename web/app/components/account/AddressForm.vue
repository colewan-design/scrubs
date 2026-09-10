<script setup lang="ts">
import type { SavedAddress, SavedAddressInput } from '~/composables/useApi'

/**
 * Add or edit one saved address (§8).
 *
 * Field order and autocomplete tokens match the checkout form exactly, so a
 * browser's saved-address autofill behaves the same in both places.
 */
const props = defineProps<{
  address?: SavedAddress | null
  saving?: boolean
  errors?: Record<string, string[]>
}>()

const emit = defineEmits<{
  submit: [value: SavedAddressInput]
  cancel: []
}>()

const provinces = ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT']

const inputClass =
  'mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] ' +
  'focus:border-edge-strong focus:outline-none'

const selectClass =
  'mt-1.5 h-11 w-full rounded-sm border border-edge bg-white px-3 text-[15px] ' +
  'focus:border-edge-strong focus:outline-none'

const form = reactive<SavedAddressInput>({
  label: props.address?.label ?? '',
  first_name: props.address?.first_name ?? '',
  last_name: props.address?.last_name ?? '',
  company: props.address?.company ?? '',
  line1: props.address?.line1 ?? '',
  line2: props.address?.line2 ?? '',
  city: props.address?.city ?? '',
  province: props.address?.province ?? '',
  postal_code: props.address?.postal_code ?? '',
  country: props.address?.country ?? 'CA',
  phone: props.address?.phone ?? '',
  is_default_shipping: props.address?.is_default_shipping ?? false,
  is_default_billing: props.address?.is_default_billing ?? false,
})

/** The first address saved becomes the default whether or not this is ticked. */
const isFirst = computed(() => !props.address)

function error(field: string) {
  return props.errors?.[field]?.[0]
}
</script>

<template>
  <form class="space-y-4" novalidate @submit.prevent="emit('submit', { ...form })">
    <div>
      <label for="addr-label" class="block text-[13px] font-medium text-ink-900">
        Nickname <span class="font-normal text-ink-400">(optional)</span>
      </label>
      <input
        id="addr-label" v-model="form.label" type="text" placeholder="Clinic, Home, Warehouse"
        :class="inputClass"
      >
      <p v-if="error('label')" class="mt-1 text-[13px] text-status-error">{{ error('label') }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
      <div>
        <label for="addr-first" class="block text-[13px] font-medium text-ink-900">First name</label>
        <input id="addr-first" v-model="form.first_name" type="text" autocomplete="given-name" :class="inputClass">
        <p v-if="error('first_name')" class="mt-1 text-[13px] text-status-error">{{ error('first_name') }}</p>
      </div>
      <div>
        <label for="addr-last" class="block text-[13px] font-medium text-ink-900">Last name</label>
        <input id="addr-last" v-model="form.last_name" type="text" autocomplete="family-name" :class="inputClass">
        <p v-if="error('last_name')" class="mt-1 text-[13px] text-status-error">{{ error('last_name') }}</p>
      </div>
    </div>

    <div>
      <label for="addr-company" class="block text-[13px] font-medium text-ink-900">
        Company <span class="font-normal text-ink-400">(optional)</span>
      </label>
      <input id="addr-company" v-model="form.company" type="text" autocomplete="organization" :class="inputClass">
    </div>

    <div>
      <label for="addr-line1" class="block text-[13px] font-medium text-ink-900">Address</label>
      <input id="addr-line1" v-model="form.line1" type="text" autocomplete="address-line1" :class="inputClass">
      <p v-if="error('line1')" class="mt-1 text-[13px] text-status-error">{{ error('line1') }}</p>
    </div>

    <div>
      <label for="addr-line2" class="block text-[13px] font-medium text-ink-900">
        Apartment, suite, unit <span class="font-normal text-ink-400">(optional)</span>
      </label>
      <input id="addr-line2" v-model="form.line2" type="text" autocomplete="address-line2" :class="inputClass">
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
      <div>
        <label for="addr-city" class="block text-[13px] font-medium text-ink-900">City</label>
        <input id="addr-city" v-model="form.city" type="text" autocomplete="address-level2" :class="inputClass">
        <p v-if="error('city')" class="mt-1 text-[13px] text-status-error">{{ error('city') }}</p>
      </div>
      <div>
        <label for="addr-province" class="block text-[13px] font-medium text-ink-900">Province</label>
        <select id="addr-province" v-model="form.province" autocomplete="address-level1" :class="selectClass">
          <option value="" disabled>Select</option>
          <option v-for="p in provinces" :key="p" :value="p">{{ p }}</option>
        </select>
        <p v-if="error('province')" class="mt-1 text-[13px] text-status-error">{{ error('province') }}</p>
      </div>
      <div>
        <label for="addr-postal" class="block text-[13px] font-medium text-ink-900">Postal code</label>
        <input
          id="addr-postal" v-model="form.postal_code" type="text" autocomplete="postal-code"
          :class="[inputClass, 'uppercase']"
        >
        <p v-if="error('postal_code')" class="mt-1 text-[13px] text-status-error">{{ error('postal_code') }}</p>
      </div>
    </div>

    <div>
      <label for="addr-phone" class="block text-[13px] font-medium text-ink-900">
        Phone <span class="font-normal text-ink-400">(optional)</span>
      </label>
      <input id="addr-phone" v-model="form.phone" type="tel" autocomplete="tel" :class="inputClass">
      <p class="mt-1 text-[12px] text-ink-400">Couriers ask for one when a delivery needs arranging.</p>
    </div>

    <div v-if="!isFirst" class="space-y-2.5 border-t border-edge-subtle pt-4">
      <label class="flex cursor-pointer items-center gap-2.5 py-1 text-[14px] text-ink-700">
        <input v-model="form.is_default_shipping" type="checkbox" class="size-4 rounded-sm border-edge accent-ink-900">
        Use as my default shipping address
      </label>
      <label class="flex cursor-pointer items-center gap-2.5 py-1 text-[14px] text-ink-700">
        <input v-model="form.is_default_billing" type="checkbox" class="size-4 rounded-sm border-edge accent-ink-900">
        Use as my default billing address
      </label>
    </div>
    <p v-else class="text-[13px] text-ink-500">
      This is your first saved address, so it becomes your default for both shipping and billing.
    </p>

    <div class="flex gap-2 pt-1">
      <UiBaseButton type="submit" :loading="props.saving">
        {{ props.address ? 'Save changes' : 'Save address' }}
      </UiBaseButton>
      <UiBaseButton type="button" variant="tertiary" @click="emit('cancel')">Cancel</UiBaseButton>
    </div>
  </form>
</template>
