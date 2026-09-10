<script setup lang="ts">
import { Check, Store, Truck } from 'lucide-vue-next'
import type { AddressInput, CheckoutQuote, Order, SavedAddress } from '~/composables/useApi'

/**
 * Checkout (§4–§7).
 *
 * Every figure on this page came from the server. The page holds no pricing
 * logic at all: it posts a destination and a choice, and re-renders whatever
 * comes back. Tax, shipping and the grand total are re-derived again when the
 * order is placed, so a stale quote can never become a charge.
 */
const api = useApi()
const auth = useAuthStore()
const cart = useCartStore()

const PROVINCES = [
  ['AB', 'Alberta'], ['BC', 'British Columbia'], ['MB', 'Manitoba'],
  ['NB', 'New Brunswick'], ['NL', 'Newfoundland and Labrador'],
  ['NS', 'Nova Scotia'], ['NT', 'Northwest Territories'], ['NU', 'Nunavut'],
  ['ON', 'Ontario'], ['PE', 'Prince Edward Island'], ['QC', 'Quebec'],
  ['SK', 'Saskatchewan'], ['YT', 'Yukon'],
] as const

const fulfillmentType = ref<'ship' | 'pickup'>('ship')
const selectedOption = ref<string | null>(null)

const contact = reactive({
  email: auth.user?.email ?? '',
  phone: auth.user?.phone ?? '',
  customer_note: '',
})

const address = reactive<AddressInput>({
  first_name: '',
  last_name: '',
  company: auth.user?.business_name ?? '',
  line1: '',
  line2: '',
  city: auth.user?.city ?? '',
  province: auth.user?.province ?? '',
  postal_code: '',
  country: 'CA',
  phone: '',
})

/**
 * Saved addresses (§8), for members only — a guest has no address book, and
 * asking the API for one would just be a guaranteed 401.
 *
 * The default is applied on load rather than offered as a choice: a customer
 * with one saved address should find checkout already filled in. Picking a
 * different one re-rates the order, because province and postal code are what
 * the shipping and tax quotes are keyed on.
 */
const { data: savedAddresses } = await useAsyncData(
  'checkout-addresses',
  async () => {
    if (!auth.isAuthenticated) return { data: [] as SavedAddress[] }

    try {
      return await api.get<{ data: SavedAddress[] }>('/account/addresses')
    } catch {
      // An address book that will not load must not take checkout down with
      // it — the form underneath still works perfectly well typed by hand.
      return { data: [] as SavedAddress[] }
    }
  },
)

const addressBook = computed(() => savedAddresses.value?.data ?? [])
const selectedAddressId = ref<number | 'new'>('new')

function applySavedAddress(saved: SavedAddress) {
  Object.assign(address, {
    first_name: saved.first_name,
    last_name: saved.last_name,
    company: saved.company ?? '',
    line1: saved.line1,
    line2: saved.line2 ?? '',
    city: saved.city,
    province: saved.province,
    postal_code: saved.postal_code,
    country: saved.country ?? 'CA',
    phone: saved.phone ?? '',
  })
}

const preferred = addressBook.value.find((a) => a.is_default_shipping) ?? addressBook.value[0]

if (preferred) {
  selectedAddressId.value = preferred.id
  applySavedAddress(preferred)
}

watch(selectedAddressId, (id) => {
  const saved = addressBook.value.find((a) => a.id === id)
  if (saved) applySavedAddress(saved)
})

const quote = ref<CheckoutQuote | null>(null)
const quoting = ref(false)
const placing = ref(false)
const errors = ref<Record<string, string[]>>({})
const generalError = ref('')

const isPickup = computed(() => fulfillmentType.value === 'pickup')

/** Shipping cannot be rated until we know where it is going. */
const canRate = computed(() => isPickup.value || address.province.length === 2)

async function refreshQuote() {
  if (!canRate.value) {
    quote.value = null

    return
  }

  quoting.value = true
  try {
    quote.value = await api.post<CheckoutQuote>('/checkout/quote', {
      fulfillment_type: fulfillmentType.value,
      province: isPickup.value ? undefined : address.province,
      postal_code: isPickup.value ? undefined : address.postal_code,
      shipping_option: selectedOption.value,
    })

    // Adopt the server's choice: it knows which options actually exist.
    selectedOption.value = quote.value.selected_shipping_option
  } catch {
    quote.value = null
  } finally {
    quoting.value = false
  }
}

// Province and postal code drive the rate table; the chosen service and the
// delivery/pickup switch change the total. Anything else is just form filling.
watch(
  () => [fulfillmentType.value, address.province, address.postal_code, selectedOption.value],
  () => refreshQuote(),
)

onMounted(() => {
  if (cart.isEmpty) {
    navigateTo('/cart')

    return
  }

  refreshQuote()
})

async function place() {
  errors.value = {}
  generalError.value = ''
  placing.value = true

  try {
    const { order } = await api.post<{ order: Order }>('/checkout', {
      ...contact,
      fulfillment_type: fulfillmentType.value,
      shipping_option: isPickup.value ? undefined : selectedOption.value,
      shipping_address: isPickup.value ? undefined : address,
    })

    await cart.refresh()
    await navigateTo(`/orders/${order.order_number}?email=${encodeURIComponent(order.email)}`)
  } catch (e: any) {
    if (e?.data?.errors) errors.value = e.data.errors
    else generalError.value = e?.data?.message || 'Something went wrong. Please try again.'
  } finally {
    placing.value = false
  }
}

const fieldError = (path: string) => errors.value[path]?.[0]

useSeoMeta({ title: 'Checkout', robots: 'noindex' })
</script>

<template>
  <div class="container-content pt-10 pb-20">
    <h1 class="font-display text-[32px] text-ink-900">Checkout</h1>

    <div class="mt-8 grid gap-12 lg:grid-cols-[1fr_360px]">
      <!-- ---------------------------------------------------------- form -->
      <form class="max-w-[560px] space-y-10" novalidate @submit.prevent="place">
        <p
          v-if="generalError"
          class="rounded-sm border border-status-error/30 bg-status-error/5 px-4 py-3 text-[14px] text-status-error"
          role="alert"
        >
          {{ generalError }}
        </p>

        <!-- Contact -->
        <section>
          <h2 class="text-[12px] font-semibold tracking-[0.08em] text-ink-500 uppercase">
            Contact
          </h2>
          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="block sm:col-span-2">
              <span class="text-[13px] font-medium text-ink-900">Email</span>
              <input
                v-model="contact.email"
                type="email"
                autocomplete="email"
                class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
              >
              <span v-if="fieldError('email')" class="mt-1 block text-[12px] text-status-error">
                {{ fieldError('email') }}
              </span>
            </label>
            <label class="block">
              <span class="text-[13px] font-medium text-ink-900">Phone <span class="font-normal text-ink-400">(optional)</span></span>
              <input
                v-model="contact.phone"
                type="tel"
                autocomplete="tel"
                class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
              >
            </label>
          </div>
        </section>

        <!-- Delivery method -->
        <section>
          <h2 class="text-[12px] font-semibold tracking-[0.08em] text-ink-500 uppercase">
            Delivery
          </h2>
          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <button
              v-for="method in [
                { key: 'ship', label: 'Ship to me', icon: Truck },
                { key: 'pickup', label: 'Local pickup', icon: Store },
              ]"
              :key="method.key"
              type="button"
              class="flex items-center gap-3 rounded-sm border px-4 py-3.5 text-left text-[14px] transition-colors"
              :class="fulfillmentType === method.key
                ? 'border-ink-900 text-ink-900'
                : 'border-edge text-ink-500 hover:border-edge-strong'"
              :aria-pressed="fulfillmentType === method.key"
              @click="fulfillmentType = method.key as 'ship' | 'pickup'"
            >
              <component :is="method.icon" :size="18" aria-hidden="true" />
              {{ method.label }}
            </button>
          </div>

          <div
            v-if="isPickup && quote?.pickup"
            class="mt-4 rounded-sm border border-edge-subtle bg-surface-warm p-4 text-[13px] text-ink-700"
          >
            <p v-if="quote.pickup.address" class="font-medium text-ink-900">{{ quote.pickup.address }}</p>
            <p v-if="quote.pickup.hours" class="mt-1">{{ quote.pickup.hours }}</p>
            <p v-if="quote.pickup.lead_time" class="mt-1 text-ink-500">{{ quote.pickup.lead_time }}</p>
            <p v-if="!quote.pickup.address" class="text-ink-500">
              Pickup details will be confirmed by email.
            </p>
          </div>
        </section>

        <!-- Shipping address -->
        <section v-if="!isPickup">
          <h2 class="text-[12px] font-semibold tracking-[0.08em] text-ink-500 uppercase">
            Shipping address
          </h2>

          <!-- Members only. Radios rather than a select: there are rarely more
               than a handful, and the whole address has to be readable before
               choosing between two that differ by a unit number. -->
          <fieldset v-if="addressBook.length" class="mt-4">
            <legend class="sr-only">Choose a saved address</legend>

            <div class="grid gap-2.5 sm:grid-cols-2">
              <label
                v-for="saved in addressBook"
                :key="saved.id"
                class="flex cursor-pointer gap-3 rounded-sm border p-3.5 text-[14px] transition-colors"
                :class="selectedAddressId === saved.id
                  ? 'border-edge-strong bg-surface-warm'
                  : 'border-edge-subtle hover:border-edge'"
              >
                <input
                  v-model="selectedAddressId"
                  type="radio"
                  :value="saved.id"
                  name="saved-address"
                  class="mt-1 size-4 shrink-0 accent-ink-900"
                >
                <span class="leading-relaxed">
                  <span class="block font-medium text-ink-900">
                    {{ saved.label || saved.name }}
                  </span>
                  <span class="block text-ink-500">{{ saved.lines.join(', ') }}</span>
                </span>
              </label>

              <label
                class="flex cursor-pointer items-center gap-3 rounded-sm border p-3.5 text-[14px] transition-colors"
                :class="selectedAddressId === 'new'
                  ? 'border-edge-strong bg-surface-warm'
                  : 'border-edge-subtle hover:border-edge'"
              >
                <input
                  v-model="selectedAddressId"
                  type="radio"
                  value="new"
                  name="saved-address"
                  class="size-4 shrink-0 accent-ink-900"
                >
                <span class="font-medium text-ink-900">Ship somewhere else</span>
              </label>
            </div>

            <p class="mt-2.5 text-[12px] text-ink-400">
              Manage these in <NuxtLink to="/account/addresses" class="underline underline-offset-4">your addresses</NuxtLink>.
              Editing one there never changes an order you have already placed.
            </p>
          </fieldset>

          <!-- Typing over a prefilled address means it is no longer that saved
               address, and the radio should stop claiming otherwise. -->
          <div class="mt-4 grid gap-4 sm:grid-cols-2" @input="selectedAddressId = 'new'">
            <label class="block">
              <span class="text-[13px] font-medium text-ink-900">First name</span>
              <input v-model="address.first_name" autocomplete="given-name" class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none">
              <span v-if="fieldError('shipping_address.first_name')" class="mt-1 block text-[12px] text-status-error">Required</span>
            </label>
            <label class="block">
              <span class="text-[13px] font-medium text-ink-900">Last name</span>
              <input v-model="address.last_name" autocomplete="family-name" class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none">
              <span v-if="fieldError('shipping_address.last_name')" class="mt-1 block text-[12px] text-status-error">Required</span>
            </label>
            <label class="block sm:col-span-2">
              <span class="text-[13px] font-medium text-ink-900">Company <span class="font-normal text-ink-400">(optional)</span></span>
              <input v-model="address.company" autocomplete="organization" class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none">
            </label>
            <label class="block sm:col-span-2">
              <span class="text-[13px] font-medium text-ink-900">Address</span>
              <input v-model="address.line1" autocomplete="address-line1" class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none">
              <span v-if="fieldError('shipping_address.line1')" class="mt-1 block text-[12px] text-status-error">Required</span>
            </label>
            <label class="block sm:col-span-2">
              <span class="text-[13px] font-medium text-ink-900">Apartment, unit <span class="font-normal text-ink-400">(optional)</span></span>
              <input v-model="address.line2" autocomplete="address-line2" class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none">
            </label>
            <label class="block">
              <span class="text-[13px] font-medium text-ink-900">City</span>
              <input v-model="address.city" autocomplete="address-level2" class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none">
              <span v-if="fieldError('shipping_address.city')" class="mt-1 block text-[12px] text-status-error">Required</span>
            </label>
            <label class="block">
              <span class="text-[13px] font-medium text-ink-900">Province</span>
              <select v-model="address.province" autocomplete="address-level1" class="mt-1.5 h-11 w-full rounded-sm border border-edge bg-white px-3 text-[15px] focus:border-edge-strong focus:outline-none">
                <option value="" disabled>Select a province</option>
                <option v-for="[code, name] in PROVINCES" :key="code" :value="code">{{ name }}</option>
              </select>
              <span v-if="fieldError('shipping_address.province')" class="mt-1 block text-[12px] text-status-error">Required</span>
            </label>
            <label class="block">
              <span class="text-[13px] font-medium text-ink-900">Postal code</span>
              <input v-model="address.postal_code" autocomplete="postal-code" class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] uppercase focus:border-edge-strong focus:outline-none">
              <span v-if="fieldError('shipping_address.postal_code')" class="mt-1 block text-[12px] text-status-error">Required</span>
            </label>
          </div>
        </section>

        <!-- Shipping method -->
        <section v-if="!isPickup">
          <h2 class="text-[12px] font-semibold tracking-[0.08em] text-ink-500 uppercase">
            Shipping method
          </h2>

          <p v-if="!canRate" class="mt-3 text-[13px] text-ink-500">
            Choose a province to see delivery options and cost.
          </p>
          <p v-else-if="quoting" class="mt-3 text-[13px] text-ink-500">Calculating…</p>
          <p v-else-if="!quote?.shipping_options.length" class="mt-3 text-[13px] text-ink-500">
            No delivery options are configured for that destination yet — please
            contact us and we will arrange it.
          </p>

          <div v-else class="mt-4 space-y-2">
            <label
              v-for="option in quote.shipping_options.filter((o) => o.code !== 'pickup')"
              :key="option.code"
              class="flex cursor-pointer items-center gap-3 rounded-sm border px-4 py-3.5 transition-colors"
              :class="selectedOption === option.code ? 'border-ink-900' : 'border-edge hover:border-edge-strong'"
            >
              <input v-model="selectedOption" type="radio" :value="option.code" name="shipping_option" class="accent-ink-900">
              <span class="flex-1 text-[14px] text-ink-900">
                {{ option.name }}
                <span v-if="option.delivery_estimate" class="block text-[12px] text-ink-500">
                  {{ option.delivery_estimate }}
                </span>
              </span>
              <span class="tabular text-[14px] font-medium" :class="option.free_threshold_applied ? 'text-sage' : 'text-ink-900'">
                {{ option.free_threshold_applied ? 'Free' : option.cost.formatted }}
              </span>
            </label>
          </div>
        </section>

        <!-- Payment -->
        <section>
          <h2 class="text-[12px] font-semibold tracking-[0.08em] text-ink-500 uppercase">
            Payment
          </h2>
          <!--
            Card payment is not wired up: the merchant account is outstanding
            client material and approval takes weeks. Rather than show a card
            form that cannot charge anything, the order is placed as Pending
            Payment and settled out of band — which is exactly how e-Transfer
            works anyway (§4).
          -->
          <div class="mt-4 rounded-sm border border-edge-subtle bg-surface-warm p-4 text-[13px] text-ink-700">
            <p class="font-medium text-ink-900">Payment on confirmation</p>
            <p class="mt-1">
              Your order is placed as <strong>Pending Payment</strong>. We will email
              payment instructions and confirm as soon as it is settled — nothing is
              charged automatically.
            </p>
            <p v-if="quote?.etransfer?.instructions" class="mt-2 whitespace-pre-line">
              {{ quote.etransfer.instructions }}
            </p>
          </div>
        </section>

        <label class="block max-w-[560px]">
          <span class="text-[13px] font-medium text-ink-900">Order note <span class="font-normal text-ink-400">(optional)</span></span>
          <textarea v-model="contact.customer_note" rows="3" class="mt-1.5 w-full rounded-sm border border-edge px-3.5 py-2.5 text-[15px] focus:border-edge-strong focus:outline-none" />
        </label>

        <UiBaseButton
          type="submit"
          size="lg"
          block
          :loading="placing"
          :disabled="placing || (!isPickup && !selectedOption)"
        >
          Place order
        </UiBaseButton>
      </form>

      <!-- ------------------------------------------------------- summary -->
      <aside class="lg:sticky lg:top-6 lg:self-start">
        <div class="rounded-sm border border-edge-subtle bg-surface-warm p-5">
          <h2 class="text-[12px] font-semibold tracking-[0.08em] text-ink-500 uppercase">
            Order summary
          </h2>

          <ul class="mt-4 space-y-3">
            <li v-for="item in cart.items" :key="item.variant_id" class="flex gap-3 text-[13px]">
              <div class="size-14 shrink-0 overflow-hidden rounded-sm bg-surface-sunken">
                <img v-if="item.image" :src="item.image" :alt="item.product_name" class="size-full object-cover">
              </div>
              <div class="min-w-0 flex-1">
                <p class="truncate text-ink-900">{{ item.product_name }}</p>
                <p class="text-ink-500">{{ item.variant_label }} · ×{{ item.qty }}</p>
              </div>
              <p class="tabular shrink-0 text-ink-900">{{ item.line_total.formatted }}</p>
            </li>
          </ul>

          <dl class="mt-5 space-y-2 border-t border-edge-subtle pt-4 text-[14px]">
            <div class="flex justify-between gap-4">
              <dt class="text-ink-700">Subtotal</dt>
              <dd class="tabular text-ink-900">{{ cart.quote?.totals.retail_subtotal.formatted }}</dd>
            </div>

            <div v-if="cart.quote && cart.quote.discount_cents > 0" class="flex justify-between gap-4">
              <dt class="text-sage">
                Wholesale saving
                <span v-if="cart.quote.tier" class="text-ink-500">· {{ cart.quote.tier.name }}</span>
              </dt>
              <dd class="tabular text-sage">−{{ cart.quote.totals.discount.formatted }}</dd>
            </div>

            <div class="flex justify-between gap-4">
              <dt class="text-ink-700">{{ isPickup ? 'Pickup' : 'Shipping' }}</dt>
              <dd class="tabular text-ink-900">
                <span v-if="quote">{{ quote.shipping.formatted }}</span>
                <span v-else class="text-[13px] text-ink-500">Enter a destination</span>
              </dd>
            </div>

            <!-- Itemised, because §6 requires the receipt to name each tax. -->
            <div v-for="line in quote?.tax.lines ?? []" :key="line.label" class="flex justify-between gap-4">
              <dt class="text-ink-700">{{ line.label }}</dt>
              <dd class="tabular text-ink-900">{{ line.amount.formatted }}</dd>
            </div>

            <div class="flex justify-between gap-4 border-t border-edge-subtle pt-3 text-[16px]">
              <dt class="font-medium text-ink-900">Total</dt>
              <dd class="tabular font-medium text-ink-900">
                <span v-if="quote">{{ quote.grand_total.formatted }}</span>
                <span v-else class="text-[13px] font-normal text-ink-500">—</span>
              </dd>
            </div>
          </dl>

          <p v-if="quote?.shipping_options.some((o) => o.free_threshold_applied)" class="mt-4 flex items-center gap-1.5 text-[13px] text-sage">
            <Check :size="14" aria-hidden="true" /> Free shipping applied
          </p>

          <p class="mt-4 text-[12px] text-ink-400">
            Prices in CAD. Taxes calculated for your delivery province.
          </p>
        </div>
      </aside>
    </div>
  </div>
</template>
