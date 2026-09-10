<script setup lang="ts">
import { Plus } from 'lucide-vue-next'
import type { SavedAddress, SavedAddressInput } from '~/composables/useApi'

/**
 * The customer's address book (§8).
 *
 * Editing an entry here never touches an order that has already been placed —
 * checkout freezes its own copy of the address onto the order, which is what
 * keeps an invoice from silently changing months later.
 */
definePageMeta({ middleware: 'auth' })

const api = useApi()

const { data, refresh } = await useAsyncData('account-addresses', () =>
  api.get<{ data: SavedAddress[] }>('/account/addresses'),
)

const addresses = computed(() => data.value?.data ?? [])

/** null = closed, 'new' = the add form, a number = editing that address. */
const editing = ref<'new' | number | null>(null)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const notice = ref('')

const editingAddress = computed(() =>
  typeof editing.value === 'number'
    ? (addresses.value.find((a) => a.id === editing.value) ?? null)
    : null,
)

function open(target: 'new' | number) {
  editing.value = target
  errors.value = {}
  notice.value = ''
}

function close() {
  editing.value = null
  errors.value = {}
}

async function save(payload: SavedAddressInput) {
  saving.value = true
  errors.value = {}

  try {
    if (editing.value === 'new') {
      await api.post('/account/addresses', payload)
      notice.value = 'Address saved.'
    } else {
      await api.patch(`/account/addresses/${editing.value}`, payload)
      notice.value = 'Address updated.'
    }

    await refresh()
    editing.value = null
  } catch (e: any) {
    if (e?.data?.errors) errors.value = e.data.errors
    else notice.value = e?.data?.message || 'Something went wrong. Please try again.'
  } finally {
    saving.value = false
  }
}

async function remove(address: SavedAddress) {
  const name = address.label || address.line1
  if (!confirm(`Delete the saved address "${name}"? Orders already placed are not affected.`)) return

  notice.value = ''

  try {
    await api.del(`/account/addresses/${address.id}`)
    await refresh()
    notice.value = 'Address deleted.'
  } catch (e: any) {
    notice.value = e?.data?.message || 'We could not delete that address. Please try again.'
  }
}

useSeoMeta({ title: 'Your addresses', robots: 'noindex' })
</script>

<template>
  <div class="container-content pt-10 pb-20">
    <h1 class="font-display text-[32px] text-ink-900">Your addresses</h1>

    <AccountNav class="mt-6" />

    <p class="mt-6 max-w-[58ch] text-[15px] text-ink-500">
      Saved addresses fill in checkout for you. Your default shipping address is the one we suggest
      first.
    </p>

    <p
      v-if="notice"
      class="mt-5 rounded-sm border border-sage/30 bg-sage-soft px-4 py-3 text-[14px] text-ink-900"
      role="status"
    >
      {{ notice }}
    </p>

    <!-- Add / edit form -->
    <section
      v-if="editing !== null"
      class="mt-6 max-w-[560px] rounded-sm border border-edge-strong p-6"
    >
      <h2 class="text-[15px] font-medium text-ink-900">
        {{ editing === 'new' ? 'Add an address' : 'Edit address' }}
      </h2>

      <AccountAddressForm
        :key="String(editing)"
        class="mt-5"
        :address="editingAddress"
        :saving="saving"
        :errors="errors"
        @submit="save"
        @cancel="close"
      />
    </section>

    <UiBaseButton v-else class="mt-6" @click="open('new')">
      <Plus :size="16" aria-hidden="true" /> Add an address
    </UiBaseButton>

    <!-- The book -->
    <p v-if="!addresses.length && editing === null" class="mt-8 text-[15px] text-ink-500">
      You have not saved an address yet.
    </p>

    <ul v-else-if="addresses.length" class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <li
        v-for="address in addresses"
        :key="address.id"
        class="flex flex-col rounded-sm border p-5"
        :class="address.is_default_shipping ? 'border-sage/40 bg-sage-soft' : 'border-edge-subtle'"
      >
        <div class="flex flex-wrap gap-1.5">
          <UiBaseBadge v-if="address.is_default_shipping" tone="sage">Default shipping</UiBaseBadge>
          <UiBaseBadge v-if="address.is_default_billing" tone="muted">Default billing</UiBaseBadge>
        </div>

        <p v-if="address.label" class="mt-2 text-[13px] tracking-[0.04em] text-ink-500 uppercase">
          {{ address.label }}
        </p>

        <address class="mt-1.5 flex-1 text-[14px] leading-relaxed text-ink-700 not-italic">
          <span class="block font-medium text-ink-900">{{ address.name }}</span>
          <span v-for="line in address.lines" :key="line" class="block">{{ line }}</span>
          <span v-if="address.phone" class="mt-1 block text-ink-500">{{ address.phone }}</span>
        </address>

        <div class="mt-4 flex gap-3 text-[13px]">
          <button
            type="button"
            class="text-ink-900 underline underline-offset-4 hover:opacity-70"
            @click="open(address.id)"
          >
            Edit
          </button>
          <button
            type="button"
            class="text-ink-500 underline underline-offset-4 hover:text-status-error"
            @click="remove(address)"
          >
            Delete
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>
