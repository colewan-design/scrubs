<script setup lang="ts">
import {
  ArrowRight,
  Check,
  Info,
  Lock,
  MapPin,
  ShieldCheck,
  Truck,
} from 'lucide-vue-next'
import type {
  AddressInput,
  CheckoutQuote,
  Order,
  PaymentMethod,
  PaymentSession,
  SavedAddress,
} from '~/composables/useApi'

/**
 * Checkout (§4–§7).
 *
 * Every figure on this page came from the server. The page holds no pricing
 * logic at all: it posts a destination and a choice, and re-renders whatever
 * comes back. Tax, shipping and the grand total are re-derived again when the
 * order is placed, so a stale quote can never become a charge.
 *
 * Checkout needs a customer account (email + password or Google). A signed-out
 * shopper is sent to sign in and brought back here, cart intact.
 */
definePageMeta({ middleware: 'auth' })

const api = useApi()
const auth = useAuthStore()
const cart = useCartStore()

// Shared with the cart, which is where the choice is now made (a cookie, so it
// survives the navigation). Writing to it here keeps the two pages agreeing if
// the shopper goes back.
const fulfillmentType = useFulfillmentPreference()
const selectedOption = ref<string | null>(null)

const contact = reactive({
  email: auth.user?.email ?? '',
  phone: auth.user?.phone ?? '',
  customer_note: '',
})

function blankAddress(): AddressInput {
  return {
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
  }
}

const address = reactive<AddressInput>(blankAddress())

/**
 * Billing follows shipping unless it is told not to. While it follows, nothing
 * is posted for it at all and the API copies the shipping address onto the
 * order — one address of record rather than two that can disagree.
 */
const billingSame = ref(true)
const billing = reactive<AddressInput>(blankAddress())

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

// --- progress --------------------------------------------------------------

/**
 * The rail across the top.
 *
 * This is a one-page checkout, so the rail reports progress rather than
 * pretending to be a wizard: each step is marked done from the state of the
 * section it names, and clicking one scrolls there. Nothing is ever hidden
 * behind a step you cannot reach.
 */
const filledIn = (a: AddressInput) =>
  Boolean(a.first_name && a.last_name && a.line1 && a.city && a.province && a.postal_code)

/**
 * A separate billing address is only ever asked for on a delivery order — the
 * tick-box lives in the shipping section, which pickup hides — so that is the
 * only time one is sent, or has to be complete.
 */
const billingSeparately = computed(() => !isPickup.value && !billingSame.value)

const addressComplete = computed(() =>
  isPickup.value || (filledIn(address) && (!billingSeparately.value || filledIn(billing))),
)

// --- payment ---------------------------------------------------------------

/**
 * Which methods exist is the server's decision, never this page's: it depends
 * on credentials the browser must not see and on an admin switch. The page
 * renders whatever comes back and nothing else.
 */
const serverMethods = computed(() => quote.value?.payment_methods ?? [])

/** Present only when PayPal has both credentials and the admin switch on. */
const paypalConfig = computed(() => quote.value?.paypal ?? null)

/**
 * Every way to pay, in the order they are offered.
 *
 * PayPal arrives separately from `payment_methods` because server-side it is
 * not a PaymentGateway — the payment is taken by PayPal's own JS SDK rather
 * than by a card form of ours. To the customer that distinction is invisible
 * and it is simply another row in the chooser, so it is folded in here.
 *
 * It goes first where it exists: it is the only method that confirms the order
 * there and then.
 */
const paymentMethods = computed<PaymentMethod[]>(() => [
  ...(paypalConfig.value
    ? [{
        code: 'paypal' as const,
        kind: 'external' as const,
        label: 'PayPal',
        description: 'Pay with your PayPal balance or a saved card. Your order is confirmed immediately.',
        test_mode: paypalConfig.value.mode === 'sandbox',
      }]
    : []),
  ...serverMethods.value,
])

const paymentMethod = ref<string | null>(null)

watch(
  paymentMethods,
  (methods) => {
    if (!methods.length) return

    // Keep the customer's choice across re-quotes, unless an admin has just
    // switched that method off underneath them.
    if (!methods.some((m) => m.code === paymentMethod.value)) {
      paymentMethod.value = methods[0]!.code
    }
  },
  { immediate: true },
)

const selectedMethod = computed(
  () => paymentMethods.value.find((m) => m.code === paymentMethod.value) ?? null,
)

/** A gateway method needs a card form and can fail; an offline one cannot. */
const payingByCard = computed(() => selectedMethod.value?.kind === 'gateway')

/** PayPal drives its own buttons, so the page's own submit button steps aside. */
const payingByPayPal = computed(() => selectedMethod.value?.kind === 'external')

/**
 * The card form has to mount before any payment exists, so the key comes with
 * the quote rather than with the session. Publishable by design: it identifies
 * the account to Stripe.js and authorises nothing.
 */
const stripePublicKey = computed(() => selectedMethod.value?.public_key ?? '')

const stripeForm = ref<{
  validate: () => Promise<string | null>
  confirm: (clientSecret: string, returnUrl: string) => Promise<string | null>
} | null>(null)

const cardReady = ref(false)
const cardBlocked = ref('')

const canPlace = computed(() => {
  if (!quote.value) return false
  if (!isPickup.value && !selectedOption.value) return false

  // The card form has to have mounted before the button means anything —
  // otherwise the first click is a guaranteed "not ready yet".
  return payingByCard.value ? cardReady.value && !cardBlocked.value : true
})

const steps = computed(() => [
  { label: 'Information', anchor: 'contact', done: /.+@.+\..+/.test(contact.email) },
  { label: 'Shipping', anchor: isPickup.value ? 'delivery' : 'shipping-address', done: addressComplete.value },
  { label: 'Payment', anchor: 'payment', done: canPlace.value },
  { label: 'Review & Place Order', anchor: 'payment', done: false },
])

/** The first thing still outstanding — or the last step, once nothing is. */
const currentStep = computed(() => {
  const i = steps.value.findIndex((s) => !s.done)

  return i === -1 ? steps.value.length - 1 : i
})

function goTo(anchor: string) {
  document.getElementById(anchor)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// --- free shipping ---------------------------------------------------------

/**
 * A ratio of cents — the one figure this page derives, because nothing is ever
 * charged from it.
 */
const freeShippingPercent = computed(() => {
  const s = cart.freeShipping
  const q = cart.quote
  if (!s || !q) return 0
  if (s.qualifies) return 100

  return s.threshold_cents > 0
    ? Math.min(100, Math.round((q.retail_subtotal_cents / s.threshold_cents) * 100))
    : 0
})

// --- placing ---------------------------------------------------------------

/** Where a customer lands once the order exists, paid or not. */
function receiptUrl(order: Order, extra = ''): string {
  return `/orders/${order.order_number}?email=${encodeURIComponent(order.email)}${extra}`
}

/** The same place, with nothing to report about the payment. */
function confirmationPath(order: Order): string {
  return receiptUrl(order)
}

/**
 * The order the customer has already placed, if any.
 *
 * PayPal needs an order to exist before it can be paid for, so the order is
 * placed when the PayPal button is clicked rather than on form submit. Holding
 * it here is what stops a second click — after a cancelled PayPal window —
 * placing a second order: the retry re-uses this one.
 */
const placedOrder = ref<Order | null>(null)

/** Shown when an order exists but is not yet paid, so it is never lost. */
const pendingNotice = ref<Order | null>(null)

/**
 * A payment PayPal has taken but not cleared (an eCheque, or a capture held for
 * review). Kept apart from both generalError and pendingNotice on purpose:
 * this is neither a failure to retry nor an order still waiting to be paid for,
 * and both of those invite exactly the second payment that must not happen.
 */
const paymentClearing = ref<{ order: Order; message: string } | null>(null)

/** Everything the API needs to place the order, independent of how it is paid. */
function orderPayload() {
  return {
    ...contact,
    fulfillment_type: fulfillmentType.value,
    shipping_option: isPickup.value ? undefined : selectedOption.value,
    // One phone field on the page: the contact number doubles as the
    // courier's, unless a saved address brought its own.
    shipping_address: isPickup.value
      ? undefined
      : { ...address, phone: address.phone || contact.phone },
    // Omitted when it matches: the API copies shipping onto the order itself.
    billing_address: billingSeparately.value ? billing : undefined,
  }
}

/**
 * Show what the API refused, where the customer will see it.
 *
 * The messages land under the fields they belong to — but those fields are in
 * the left column and the button that was just pressed is in the right, often
 * a screen or two away. Without the summary and the scroll, a missing billing
 * postal code looks exactly like a button that does nothing.
 */
async function showFieldErrors(fields: Record<string, string[]>) {
  errors.value = fields
  generalError.value = 'Some details are missing or need another look. Please check the highlighted fields.'

  await nextTick()

  // The first field at fault, or the top of the form where the summary is.
  const target = document.querySelector('#checkout-form [aria-invalid="true"]')
    ?? document.getElementById('checkout-form')

  target?.scrollIntoView({ behavior: 'smooth', block: target.id === 'checkout-form' ? 'start' : 'center' })
}

/**
 * Place the order, then take the payment. In that order, and never the reverse.
 *
 * THE SEQUENCE, AND WHY IT IS THIS ONE
 *
 *  1. Validate the card first. It is the only step that is still free to fail:
 *     a mistyped number caught here costs nothing, while the same mistake
 *     caught later has already created an order holding stock.
 *
 *  2. Place the order. The server reserves stock and opens the payment.
 *
 *  3. Confirm the card against that payment.
 *
 * Once step 2 has succeeded the order EXISTS, and every path from here leads to
 * its receipt — including the failures. A declined card must not strand a
 * customer on a checkout page whose cart the server has already emptied; they
 * go to the order, which offers a retry against the same order rather than
 * letting them place a second one.
 *
 * Note what this function never does: mark anything paid. `confirm()` resolving
 * means the customer finished, not that the money arrived. Only Stripe's
 * webhook decides that.
 *
 * PayPal never comes through here. Its button places the order itself, because
 * PayPal needs an order to exist before it can be paid for — see startPayPal().
 */
async function place() {
  errors.value = {}
  generalError.value = ''

  if (payingByCard.value) {
    const problem = await stripeForm.value?.validate()

    if (problem) {
      generalError.value = problem
      goTo('payment')

      return
    }
  }

  placing.value = true

  let placed: Order | null = null

  try {
    const response = await api.post<{
      order: Order
      payment?: PaymentSession
      payment_error?: string
    }>('/checkout', {
      ...orderPayload(),
      payment_method: paymentMethod.value,
    })

    placed = response.order

    if (payingByCard.value) {
      // The provider was unreachable when the order was placed. The order
      // stands and holds its stock; the receipt page offers the retry.
      if (!response.payment) {
        await cart.refresh()
        await navigateTo(receiptUrl(placed, '&payment=unavailable'))

        return
      }

      const problem = await stripeForm.value?.confirm(
        response.payment.client_secret,
        // Absolute, and it has to be: Stripe redirects a 3-D Secure challenge
        // back here from its own domain.
        `${window.location.origin}${receiptUrl(placed)}`,
      )

      if (problem) {
        await cart.refresh()
        await navigateTo(receiptUrl(placed, '&payment=failed'))

        return
      }
    }

    await cart.refresh()
    await navigateTo(receiptUrl(placed))
  } catch (e: any) {
    // Only reachable before the order exists — everything after step 2 routes
    // to the receipt above. If an order did somehow get placed, send them to it
    // rather than inviting a duplicate.
    if (placed) {
      await cart.refresh()
      await navigateTo(receiptUrl(placed, '&payment=failed'))

      return
    }

    if (e?.data?.errors) await showFieldErrors(e.data.errors)
    else generalError.value = e?.data?.message || 'Something went wrong. Please try again.'
  } finally {
    placing.value = false
  }
}

/**
 * PayPal's createOrder step: make sure an order of ours exists, and return the
 * PayPal order id opened against it.
 *
 * The cart is deliberately NOT refreshed here even though the server has
 * emptied it. The summary on the right is what the customer is checking against
 * while the PayPal window is open, and blanking it mid-payment is alarming. It
 * is refreshed once the payment completes.
 */
async function startPayPal(): Promise<string> {
  errors.value = {}
  generalError.value = ''
  placing.value = true

  try {
    // Second attempt: re-use the order rather than placing another.
    if (placedOrder.value) {
      const { order_id } = await api.post<{ order_id: string }>(
        `/orders/${placedOrder.value.order_number}/paypal/create`,
        { email: placedOrder.value.email },
      )

      return order_id
    }

    const response = await api.post<{
      order: Order
      paypal: { order_id: string } | null
      payment_error?: string
    }>('/checkout', { ...orderPayload(), payment_method: 'paypal' })

    // Set before the error check: the order exists either way, and losing the
    // reference would leave the customer with a placed order they cannot find.
    placedOrder.value = response.order

    if (!response.paypal) {
      pendingNotice.value = response.order
      generalError.value = response.payment_error
        || 'Your order was placed, but we could not open PayPal. Please try again.'

      throw new Error(generalError.value)
    }

    return response.paypal.order_id
  } catch (e: any) {
    if (e?.data?.errors) await showFieldErrors(e.data.errors)
    else if (!generalError.value) {
      generalError.value = e?.data?.message || 'We could not start the payment. Please try again.'
    }

    // Rethrown so the SDK abandons the payment rather than opening a window
    // against an order that does not exist.
    throw e
  } finally {
    placing.value = false
  }
}

/** PayPal's onApprove step: capture, then show the confirmation. */
async function capturePayPal(paypalOrderId: string) {
  const order = placedOrder.value

  if (!order) return

  placing.value = true
  generalError.value = ''

  try {
    const response = await api.post<{
      order: Order
      payment_pending?: boolean
      message?: string
    }>(
      `/orders/${order.order_number}/paypal/capture`,
      { paypal_order_id: paypalOrderId, email: order.email },
    )

    await cart.refresh()

    // 202: the money is on its way but has not arrived, so there is no
    // confirmation to navigate to yet. The customer is told what happened and
    // the buttons are withdrawn rather than left inviting another attempt.
    if (response.payment_pending) {
      paymentClearing.value = {
        order: response.order,
        message: response.message
          || 'PayPal has taken your payment but has not cleared it yet. Your order is saved and we will '
            + 'confirm it as soon as the money arrives — please do not pay for it again.',
      }

      return
    }

    await navigateTo(confirmationPath(response.order))
  } catch (e: any) {
    // The order stands whatever happened to the payment, so the customer is
    // pointed at it rather than left thinking the whole thing failed.
    pendingNotice.value = order
    generalError.value = e?.data?.message
      || 'We could not confirm that payment. Please check your order before trying again.'
  } finally {
    placing.value = false
  }
}

function onPayPalCancel() {
  if (placedOrder.value) pendingNotice.value = placedOrder.value
}

function onPayPalError(message: string) {
  // The SDK reports our own thrown errors here too, and startPayPal has
  // already set a better message in that case.
  if (message || !generalError.value) {
    generalError.value = message || 'PayPal could not complete that payment. Please try again.'
  }
}

/**
 * PayPal opens a window on click, so the form cannot be validated on submit the
 * way the offline path is — the details have to be complete before the button
 * becomes usable.
 */
const contactComplete = computed(() => /.+@.+\..+/.test(contact.email))

const paypalReady = computed(
  () => canPlace.value && contactComplete.value && addressComplete.value && !placing.value,
)

const fieldError = (path: string) => errors.value[path]?.[0]

/** Read as "CAD $34.00": currency stated, never assumed. */
const money = (m: { currency: string; formatted: string } | null | undefined) =>
  m ? `${m.currency} ${m.formatted}` : null

const deliveryMethods = [
  {
    key: 'ship',
    icon: Truck,
    title: 'Shipping',
    line1: 'Ship to my address',
    line2: 'Fast, reliable delivery across Canada.',
  },
  {
    key: 'pickup',
    icon: MapPin,
    title: 'Local Pickup',
    line1: 'Pick up from our Canadian location',
    line2: 'Pickup details are confirmed by email.',
  },
]

const trust = [
  {
    icon: ShieldCheck,
    title: 'Secure checkout',
    body: 'Sent over an encrypted connection.',
  },
  {
    icon: Truck,
    title: 'Canadian business',
    body: 'Proudly serving healthcare across Canada.',
  },
]

useSeoMeta({ title: 'Checkout', robots: 'noindex' })
</script>

<template>
  <div class="container-content pt-10 pb-20">
    <!-- Header -->
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="font-display text-[32px] text-ink-900 sm:text-[38px]">Checkout</h1>
        <p class="mt-1 text-[14px] text-ink-500">
          Secure checkout. Fast, easy and trusted by healthcare professionals across Canada.
        </p>
      </div>
      <p class="flex items-center gap-1.5 text-[13px] text-ink-500">
        <Lock :size="13" aria-hidden="true" />
        Your information is secure and encrypted.
      </p>
    </div>

    <!-- Progress rail -->
    <ol class="mt-8 flex gap-3 overflow-x-auto pb-1 sm:gap-0">
      <li
        v-for="(step, i) in steps"
        :key="step.label"
        class="flex min-w-0 shrink-0 items-center gap-3 sm:flex-1"
      >
        <button
          type="button"
          class="flex shrink-0 items-center gap-2 text-left"
          :aria-current="i === currentStep ? 'step' : undefined"
          @click="goTo(step.anchor)"
        >
          <span
            class="grid size-7 shrink-0 place-items-center rounded-full text-[12px] font-semibold transition-colors"
            :class="step.done || i === currentStep
              ? 'bg-ink-900 text-white'
              : 'bg-surface-warm-deep text-ink-500'"
          >
            <Check v-if="step.done" :size="14" aria-hidden="true" />
            <template v-else>{{ i + 1 }}</template>
          </span>
          <!-- On a phone only the step being worked on is named; four labels
               do not fit 390px, and the rail would scroll the last one off. -->
          <span
            class="text-[13px] whitespace-nowrap"
            :class="i === currentStep
              ? 'font-medium text-ink-900'
              : 'hidden text-ink-500 sm:inline'"
          >
            {{ step.label }}
          </span>
        </button>

        <span
          v-if="i < steps.length - 1"
          class="hidden h-px flex-1 sm:block"
          :class="step.done ? 'bg-ink-900' : 'bg-edge-subtle'"
          aria-hidden="true"
        />
      </li>
    </ol>

    <div class="mt-8 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_400px] lg:gap-8">
      <!-- ---------------------------------------------------------- form -->
      <form id="checkout-form" class="min-w-0 space-y-4" novalidate @submit.prevent="place">
        <!-- Shown here for the card and offline paths, and beside the PayPal
             button for PayPal — whichever place the customer is actually
             looking at when it goes wrong. Never both. -->
        <p
          v-if="generalError && !payingByPayPal"
          class="rounded-md border border-status-error/30 bg-status-error/5 px-4 py-3 text-[14px] text-status-error"
          role="alert"
        >
          {{ generalError }}
        </p>

        <!-- 1. Contact -->
        <section id="contact" class="rounded-md border border-edge-subtle p-5">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex gap-3.5">
              <span
                class="grid size-8 shrink-0 place-items-center rounded-full bg-surface-warm-deep
                       text-[13px] font-semibold text-ink-700"
              >
                1
              </span>
              <div>
                <h2 class="font-body text-[16px] font-semibold text-ink-900">Contact information</h2>
                <p class="mt-0.5 text-[13px] text-ink-500">
                  We'll use this email to send your order confirmation and updates.
                </p>
              </div>
            </div>
          </div>

          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <CheckoutField
              v-model="contact.email"
              label="Email address"
              type="email"
              autocomplete="email"
              :error="fieldError('email')"
            />
            <CheckoutField
              v-model="contact.phone"
              label="Phone number"
              optional
              type="tel"
              autocomplete="tel"
            />
          </div>
        </section>

        <!-- 2. Shipping address -->
        <section
          v-if="!isPickup"
          id="shipping-address"
          class="rounded-md border border-edge-subtle p-5"
        >
          <div class="flex gap-3.5">
            <span
              class="grid size-8 shrink-0 place-items-center rounded-full bg-surface-warm-deep
                     text-[13px] font-semibold text-ink-700"
            >
              2
            </span>
            <div>
              <h2 class="font-body text-[16px] font-semibold text-ink-900">Shipping address</h2>
              <p class="mt-0.5 text-[13px] text-ink-500">
                Enter the address where you'd like your order delivered.
              </p>
            </div>
          </div>

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
                  <span class="block font-medium text-ink-900">{{ saved.label || saved.name }}</span>
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
              Manage these in
              <NuxtLink to="/account/addresses" class="underline underline-offset-4">your addresses</NuxtLink>.
              Editing one there never changes an order you have already placed.
            </p>
          </fieldset>

          <!-- Typing over a prefilled address means it is no longer that saved
               address, and the radio should stop claiming otherwise. -->
          <div class="mt-4" @input="selectedAddressId = 'new'">
            <CheckoutAddressFields
              :address="address"
              :errors="errors"
              prefix="shipping_address"
              scope="shipping"
            />
          </div>

          <label class="mt-4 flex cursor-pointer items-center gap-2.5 text-[14px] text-ink-900">
            <input v-model="billingSame" type="checkbox" class="size-4 shrink-0 accent-ink-900">
            Use this address for billing as well
          </label>

          <div v-if="!billingSame" class="mt-4 border-t border-edge-subtle pt-4">
            <h3 class="font-body text-[14px] font-medium text-ink-900">Billing address</h3>
            <p class="mt-0.5 mb-3 text-[13px] text-ink-500">
              Where your payment is registered.
            </p>
            <CheckoutAddressFields
              :address="billing"
              :errors="errors"
              prefix="billing_address"
              scope="billing"
            />
          </div>
        </section>

        <!-- 3. Delivery method -->
        <section id="delivery" class="rounded-md border border-edge-subtle p-5">
          <div class="flex gap-3.5">
            <span
              class="grid size-8 shrink-0 place-items-center rounded-full bg-surface-warm-deep
                     text-[13px] font-semibold text-ink-700"
            >
              {{ isPickup ? 2 : 3 }}
            </span>
            <div>
              <h2 class="font-body text-[16px] font-semibold text-ink-900">Delivery method</h2>
              <p class="mt-0.5 text-[13px] text-ink-500">
                Choose how you'd like to receive your order.
              </p>
            </div>
          </div>

          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <label
              v-for="method in deliveryMethods"
              :key="method.key"
              class="flex cursor-pointer items-center gap-3 rounded-md border p-4 transition-colors"
              :class="fulfillmentType === method.key
                ? 'border-ink-900 bg-surface-sunken'
                : 'border-edge-subtle hover:border-edge'"
            >
              <input
                v-model="fulfillmentType"
                type="radio"
                name="fulfillment"
                :value="method.key"
                class="size-4 shrink-0 accent-ink-900"
              >
              <span class="grid size-10 shrink-0 place-items-center rounded-full bg-surface-warm text-ink-700">
                <component :is="method.icon" :size="18" aria-hidden="true" />
              </span>
              <span class="min-w-0">
                <span class="block text-[14px] font-medium text-ink-900">{{ method.title }}</span>
                <span class="block text-[13px] leading-snug text-ink-500">{{ method.line1 }}</span>
                <span class="block text-[13px] leading-snug text-ink-500">{{ method.line2 }}</span>
              </span>
            </label>
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

        <!-- 4. Shipping method -->
        <section
          v-if="!isPickup"
          id="shipping-method"
          class="rounded-md border border-edge-subtle p-5"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex gap-3.5">
              <span
                class="grid size-8 shrink-0 place-items-center rounded-full bg-surface-warm-deep
                       text-[13px] font-semibold text-ink-700"
              >
                4
              </span>
              <h2 class="font-body text-[16px] font-semibold text-ink-900">Shipping method</h2>
            </div>

            <NuxtLink
              to="/policies/shipping"
              class="flex items-center gap-1.5 text-[13px] font-medium text-ink-900 underline-offset-4 hover:underline"
            >
              See our shipping policy
              <ArrowRight :size="14" aria-hidden="true" />
            </NuxtLink>
          </div>

          <!-- What another item or two is worth, at the last moment it is still
               possible to add one. -->
          <div v-if="cart.freeShipping" class="mt-4 flex gap-3.5 rounded-sm bg-surface-warm p-4">
            <Truck :size="20" class="mt-0.5 shrink-0 text-ink-700" aria-hidden="true" />
            <div class="min-w-0 flex-1">
              <p class="text-[14px] font-medium text-ink-900">
                <template v-if="cart.freeShipping.qualifies">Free shipping applied</template>
                <template v-else>
                  Free shipping on orders {{ money(cart.freeShipping.threshold) }}+
                </template>
              </p>
              <p v-if="!cart.freeShipping.qualifies && cart.freeShipping.gap" class="text-[13px] text-ink-700">
                You're <span class="tabular font-medium">{{ money(cart.freeShipping.gap) }}</span>
                away from free shipping.
              </p>

              <div
                class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-white"
                role="progressbar"
                :aria-valuenow="freeShippingPercent"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="Progress toward free shipping"
              >
                <div
                  class="h-full rounded-full transition-all duration-300"
                  :class="cart.freeShipping.qualifies ? 'bg-sage' : 'bg-ink-900'"
                  :style="{ width: `${freeShippingPercent}%` }"
                />
              </div>

              <p class="tabular mt-1.5 flex justify-between text-[12px] text-ink-500">
                <span>{{ money(cart.quote?.totals.retail_subtotal) }}</span>
                <span>{{ money(cart.freeShipping.threshold) }}</span>
              </p>
            </div>
          </div>

          <p v-if="!canRate" class="mt-4 text-[13px] text-ink-500">
            Choose a province to see delivery options and cost.
          </p>
          <!-- Only before the first quote lands. Re-rating keeps the options on
               screen and dims them, rather than emptying the section every time
               a postal code gains a character. -->
          <p v-else-if="quoting && !quote" class="mt-4 text-[13px] text-ink-500">Calculating…</p>
          <p v-else-if="!quote?.shipping_options.length" class="mt-4 text-[13px] text-ink-500">
            No delivery options are configured for that destination yet — please contact us and we
            will arrange it.
          </p>

          <div
            v-else
            class="mt-4 space-y-2 transition-opacity"
            :class="quoting && 'opacity-60'"
            :aria-busy="quoting || undefined"
          >
            <label
              v-for="option in quote.shipping_options.filter((o) => o.code !== 'pickup')"
              :key="option.code"
              class="flex cursor-pointer items-center gap-3 rounded-sm border px-4 py-3.5 transition-colors"
              :class="selectedOption === option.code
                ? 'border-ink-900 bg-surface-sunken'
                : 'border-edge-subtle hover:border-edge'"
            >
              <input
                v-model="selectedOption"
                type="radio"
                :value="option.code"
                name="shipping_option"
                class="size-4 shrink-0 accent-ink-900"
              >
              <span class="min-w-0 flex-1 text-[14px] text-ink-900">
                {{ option.name }}
                <span v-if="option.delivery_estimate" class="block text-[13px] text-ink-500">
                  {{ option.delivery_estimate }}
                </span>
              </span>
              <span
                class="tabular shrink-0 text-[14px] font-medium"
                :class="option.free_threshold_applied ? 'text-sage' : 'text-ink-900'"
              >
                {{ option.free_threshold_applied ? 'Free' : money(option.cost) }}
              </span>
            </label>
          </div>
        </section>

        <!-- Not in the redesign, but it is the only way a customer can tell us
             something about the order, so it stays. -->
        <section class="rounded-md border border-edge-subtle p-5">
          <CheckoutField label="Order note" optional>
            <template #default="{ id, control }">
              <textarea :id="id" v-model="contact.customer_note" rows="2" :class="control" />
            </template>
          </CheckoutField>
        </section>
      </form>

      <!-- ------------------------------------------------------- summary -->
      <aside class="space-y-4 lg:sticky lg:top-6 lg:self-start">
        <div class="rounded-md border border-edge-subtle p-5">
          <div class="flex items-baseline justify-between gap-4">
            <h2 class="font-display text-[20px] text-ink-900">
              Order summary
              <span class="font-body text-[13px] text-ink-500">
                ({{ cart.itemCount }} {{ cart.itemCount === 1 ? 'item' : 'items' }})
              </span>
            </h2>
            <NuxtLink
              to="/cart"
              class="shrink-0 text-[13px] font-medium text-ink-900 underline underline-offset-4"
            >
              Edit Cart
            </NuxtLink>
          </div>

          <ul class="mt-4 divide-y divide-edge-subtle border-y border-edge-subtle">
            <li v-for="item in cart.items" :key="item.variant_id" class="flex items-center gap-3 py-3">
              <div class="relative size-14 shrink-0">
                <div class="size-full overflow-hidden rounded-sm bg-surface-sunken">
                  <img
                    v-if="item.image"
                    :src="item.image"
                    :alt="item.product_name"
                    class="size-full object-cover"
                  >
                </div>
                <span
                  class="tabular absolute -top-1.5 -right-1.5 grid size-5 place-items-center rounded-full
                         bg-ink-900 text-[11px] font-medium text-white"
                  aria-hidden="true"
                >
                  {{ item.qty }}
                </span>
              </div>

              <div class="min-w-0 flex-1 text-[13px]">
                <p class="truncate font-medium text-ink-900">{{ item.product_name }}</p>
                <p class="text-ink-500">{{ item.variant_label }}</p>
                <span class="sr-only">Quantity {{ item.qty }}</span>
              </div>

              <p class="tabular shrink-0 text-[13px] text-ink-900">{{ money(item.line_total) }}</p>
            </li>
          </ul>

          <dl class="mt-4 space-y-2.5 text-[14px]">
            <div class="flex justify-between gap-4">
              <dt class="text-ink-700">Subtotal</dt>
              <dd class="tabular text-ink-900">{{ money(cart.quote?.totals.retail_subtotal) }}</dd>
            </div>

            <div v-if="cart.quote && cart.quote.discount_cents > 0" class="flex justify-between gap-4">
              <dt class="text-sage">
                Wholesale discount
                <span v-if="cart.quote.tier" class="text-ink-500">· {{ cart.quote.tier.name }}</span>
              </dt>
              <dd class="tabular text-sage">−{{ money(cart.quote.totals.discount) }}</dd>
            </div>

            <div class="flex justify-between gap-4">
              <dt class="flex items-center gap-1.5 text-ink-700">
                {{ isPickup ? 'Pickup' : 'Shipping' }}
                <Info
                  :size="13"
                  class="shrink-0 text-ink-400"
                  title="Rated for your destination once a province is entered."
                  aria-hidden="true"
                />
                <span class="sr-only">Rated for your destination once a province is entered.</span>
              </dt>
              <dd class="tabular text-ink-900">
                <span v-if="quote">{{ money(quote.shipping) }}</span>
                <span v-else class="text-[13px] text-ink-500">Enter a destination</span>
              </dd>
            </div>

            <!-- Itemised, because §6 requires the receipt to name each tax. -->
            <div
              v-for="line in quote?.tax.lines ?? []"
              :key="line.label"
              class="flex justify-between gap-4"
            >
              <dt class="flex items-center gap-1.5 text-ink-700">
                Estimated {{ line.label }}
                <Info
                  :size="13"
                  class="shrink-0 text-ink-400"
                  title="Calculated from your delivery province and confirmed when the order is placed."
                  aria-hidden="true"
                />
                <span class="sr-only">
                  Calculated from your delivery province and confirmed when the order is placed.
                </span>
              </dt>
              <dd class="tabular text-ink-900">{{ money(line.amount) }}</dd>
            </div>
          </dl>

          <div class="mt-4 flex items-baseline justify-between gap-4 rounded-sm bg-surface-warm px-4 py-3.5">
            <p class="font-display text-[18px] text-ink-900">Total (CAD)</p>
            <p class="tabular text-[20px] font-semibold text-ink-900">
              <span v-if="quote">{{ money(quote.grand_total) }}</span>
              <span v-else class="text-[14px] font-normal text-ink-500">—</span>
            </p>
          </div>

          <p
            v-if="cart.quote && cart.quote.discount_cents > 0"
            class="mt-3 flex items-center gap-2 rounded-sm bg-sage-soft px-3.5 py-2.5 text-[13px] text-sage"
          >
            <Check :size="15" class="shrink-0" aria-hidden="true" />
            <span>
              You're saving
              <span class="tabular font-semibold">{{ money(cart.quote.totals.discount) }}</span>
              with wholesale pricing.
            </span>
          </p>
        </div>

        <!-- 5. Payment -->
        <div id="payment" class="rounded-md border border-edge-subtle p-5">
          <div class="flex gap-3.5">
            <span
              class="grid size-8 shrink-0 place-items-center rounded-full bg-surface-warm-deep
                     text-[13px] font-semibold text-ink-700"
            >
              5
            </span>
            <div>
              <h2 class="font-body text-[16px] font-semibold text-ink-900">Payment</h2>
              <p class="mt-0.5 flex items-center gap-1.5 text-[13px] text-ink-500">
                <Lock :size="12" aria-hidden="true" />
                <template v-if="payingByCard">Encrypted and handled by Stripe.</template>
                <template v-else-if="payingByPayPal">
                  Card details are handled by PayPal, never by us.
                </template>
                <template v-else>Nothing is charged on this page.</template>
              </p>
            </div>
          </div>

          <!-- A payment PayPal is still clearing. Deliberately above the
               Pending Payment notice and mutually exclusive with it: that one
               offers a way to pay now, which is the one thing not to do here. -->
          <p
            v-if="paymentClearing"
            class="mt-4 rounded-sm border border-edge bg-surface-warm px-4 py-3 text-[13px] leading-relaxed text-ink-700"
            role="status"
          >
            {{ paymentClearing.message }}
            <NuxtLink
              :to="confirmationPath(paymentClearing.order)"
              class="font-medium text-ink-900 underline underline-offset-4"
            >
              {{ paymentClearing.order.order_number }}
            </NuxtLink>
          </p>

          <!-- An order that exists but is not yet paid. Shown rather than
               swallowed: the customer's stock is reserved against it, and they
               need a way back to it if anything went wrong. -->
          <p
            v-if="pendingNotice && !paymentClearing"
            class="mt-4 rounded-sm border border-edge bg-surface-warm px-4 py-3 text-[13px] leading-relaxed text-ink-700"
          >
            Your order
            <NuxtLink :to="confirmationPath(pendingNotice)" class="font-medium text-ink-900 underline underline-offset-4">
              {{ pendingNotice.order_number }}
            </NuxtLink>
            is saved as Pending Payment. You can pay for it now, or find it again from that link.
          </p>

          <!-- Everything below is a way to pay, so all of it is hidden while a
               payment is clearing: the money for this order is already on its
               way, and a second one must not be invited. -->
          <template v-if="!paymentClearing">
            <!-- Method chooser. Hidden entirely when there is only one way to
                 pay: a radio group of one is a decision nobody has to make. -->
            <fieldset v-if="paymentMethods.length > 1" class="mt-4">
              <legend class="sr-only">Payment method</legend>
              <div class="space-y-2">
                <label
                  v-for="method in paymentMethods"
                  :key="method.code"
                  class="flex cursor-pointer gap-3 rounded-sm border p-3.5 transition-colors"
                  :class="
                    paymentMethod === method.code
                      ? 'border-ink-900 bg-surface-sunken'
                      : 'border-edge hover:border-edge-strong/40'
                  "
                >
                  <input
                    v-model="paymentMethod"
                    type="radio"
                    name="payment-method"
                    :value="method.code"
                    class="mt-0.5 size-4 shrink-0 accent-ink-900"
                  >
                  <span class="min-w-0">
                    <span class="block text-[14px] font-medium text-ink-900">{{ method.label }}</span>
                    <span class="mt-0.5 block text-[12px] leading-relaxed text-ink-500">
                      {{ method.description }}
                    </span>
                  </span>
                </label>
              </div>
            </fieldset>

            <!-- ------------------------------------------------------ card -->
            <!-- The element mounts a Stripe-hosted iframe; no card number ever
                 touches this page or this server (§12, PCI SAQ-A). -->
            <div v-if="payingByCard" class="mt-4">
              <ClientOnly>
                <CheckoutStripePayment
                  v-if="quote && selectedMethod && stripePublicKey"
                  ref="stripeForm"
                  :public-key="stripePublicKey"
                  :amount-cents="quote.grand_total.cents"
                  :test-mode="selectedMethod.test_mode"
                  @ready="cardReady = true"
                  @error="cardBlocked = $event"
                />
                <template #fallback>
                  <p class="py-6 text-center text-[13px] text-ink-500">Loading secure card form…</p>
                </template>
              </ClientOnly>
            </div>

            <!-- ---------------------------------------------------- PayPal -->
            <div v-else-if="payingByPayPal && paypalConfig" class="mt-4">
              <p
                v-if="generalError"
                class="mb-3 rounded-sm border border-status-error/30 bg-status-error/5 px-3.5 py-2.5 text-[13px] leading-relaxed text-status-error"
                role="alert"
              >
                {{ generalError }}
              </p>

              <p
                v-if="paypalConfig.mode === 'sandbox'"
                class="mb-3 rounded-sm border border-status-error/30 bg-status-error/5 px-3.5 py-2.5 text-[12px] font-medium text-status-error"
                role="alert"
              >
                PayPal is in test mode. No real payment will be taken.
              </p>

              <CheckoutPayPalButtons
                :client-id="paypalConfig.client_id"
                :currency="paypalConfig.currency"
                :disabled="!paypalReady"
                :create-order="startPayPal"
                :on-approved="capturePayPal"
                @cancel="onPayPalCancel"
                @error="onPayPalError"
              />

              <p class="mt-3 text-[12px] leading-relaxed text-ink-500">
                You'll pay
                <span v-if="quote" class="tabular font-medium text-ink-700">{{ money(quote.grand_total) }}</span>
                in the PayPal window. Your order is confirmed the moment the payment clears.
              </p>
            </div>

            <!-- --------------------------------------------------- offline -->
            <!-- Instructions, not a form. The order is placed as Pending
                 Payment and a human settles it (§4). -->
            <div v-else class="mt-4 rounded-sm border border-edge bg-surface-sunken p-4">
              <p class="flex items-center gap-2.5 text-[14px] font-medium text-ink-900">
                <span
                  class="size-4 shrink-0 rounded-full border-[5px] border-ink-900"
                  aria-hidden="true"
                />
                {{ selectedMethod?.label ?? 'Payment on confirmation' }}
              </p>
              <p class="mt-2 text-[13px] leading-relaxed text-ink-700">
                Your order is placed as
                <strong class="font-medium text-ink-900">Pending Payment</strong>. We email payment
                instructions and confirm the order as soon as it is settled — no card is charged
                automatically.
              </p>
              <p
                v-if="quote?.etransfer?.instructions && paymentMethod === 'etransfer'"
                class="mt-2 text-[13px] leading-relaxed whitespace-pre-line text-ink-700"
              >
                {{ quote.etransfer.instructions }}
              </p>
            </div>

            <!-- Every path but PayPal places the order from here. PayPal's own
                 button does it instead, because PayPal needs the order to exist
                 before it will open — a second button would be a second order. -->
            <UiBaseButton
              v-if="!payingByPayPal"
              type="submit"
              form="checkout-form"
              size="lg"
              block
              class="mt-4"
              :loading="placing"
              :disabled="placing || !canPlace"
            >
              <Lock v-if="!placing" :size="15" aria-hidden="true" />
              Place Order<template v-if="quote"> — {{ money(quote.grand_total) }}</template>
            </UiBaseButton>
          </template>

          <p class="mt-3 text-center text-[12px] leading-relaxed text-ink-500">
            By placing your order, you agree to our
            <NuxtLink to="/policies/terms" class="underline underline-offset-4">Terms &amp; Conditions</NuxtLink>
            and
            <NuxtLink to="/policies/privacy" class="underline underline-offset-4">Privacy Policy</NuxtLink>.
          </p>

          <ul class="mt-5 grid grid-cols-3 gap-3 border-t border-edge-subtle pt-5 text-center">
            <li v-for="item in trust" :key="item.title">
              <component :is="item.icon" :size="18" class="mx-auto text-ink-700" aria-hidden="true" />
              <p class="mt-1.5 text-[12px] font-medium text-ink-900">{{ item.title }}</p>
              <p class="text-[11px] leading-snug text-ink-500">{{ item.body }}</p>
            </li>
            <!-- Emoji rather than an icon: lucide has no maple leaf, and a
                 hand-rolled path would be the one piece of fabricated brand art
                 on the site. Matches SiteFooter and ShopTrustRow. -->
            <li>
              <span class="text-[18px] leading-none" aria-hidden="true">🍁</span>
              <p class="mt-1.5 text-[12px] font-medium text-ink-900">Canadian healthcare</p>
              <p class="text-[11px] leading-snug text-ink-500">Better teams. Healthier communities.</p>
            </li>
          </ul>
        </div>
      </aside>
    </div>
  </div>
</template>
