<script setup lang="ts">
import { loadStripe, type Stripe, type StripeElements } from '@stripe/stripe-js'

/**
 * The card form (§4).
 *
 * WHAT IS AND IS NOT IN THIS COMPONENT
 *
 * No card number ever reaches this code. The Payment Element renders inside an
 * iframe served by Stripe; what is mounted below is an empty div and a handle
 * to a form living on someone else's origin. That is the whole reason the PCI
 * position stays SAQ-A — and it is why nothing here reads, validates or logs a
 * card, and why it must stay that way.
 *
 * WHY THE DEFERRED-INTENT FLOW
 *
 * Stripe can mount the element from an amount alone, before any intent exists.
 * That matters because the alternative is creating a PaymentIntent the moment
 * checkout loads — one per abandoned basket, each an authorisation attached to
 * an order that was never placed. Here the order is placed first, its intent is
 * created against the real total, and only then is the card confirmed. Nothing
 * exists at Stripe for a customer who changes their mind.
 *
 * The page drives this in two steps: `validate()` before it places the order,
 * `confirm()` after. Splitting them is what lets a card error be caught while
 * it is still free to fix, instead of after an order row exists.
 */
/**
 * Two modes, and which one applies depends on whether a payment already exists.
 *
 *   DEFERRED (amountCents, no clientSecret) — checkout, before the order is
 *   placed. Mounts from an amount alone so nothing is created at Stripe for a
 *   basket the customer abandons.
 *
 *   EXISTING (clientSecret) — retrying an order that is already placed. The
 *   intent is there and the element attaches to it, so the retry pays the
 *   original order instead of opening a second authorisation against it.
 */
const props = defineProps<{
  publicKey: string
  amountCents?: number
  clientSecret?: string
  testMode?: boolean
}>()

const emit = defineEmits<{ ready: []; error: [message: string] }>()

const mountPoint = ref<HTMLDivElement | null>(null)
const loading = ref(true)
const failed = ref('')

let stripe: Stripe | null = null
let elements: StripeElements | null = null

/**
 * Stripe's own theme, pushed as close to the site's tokens as its appearance
 * API allows. The element is in a cross-origin iframe, so the page's stylesheet
 * cannot reach it — every value has to be handed over explicitly or the card
 * form arrives looking like a different website.
 */
function appearance() {
  return {
    theme: 'stripe' as const,
    variables: {
      colorPrimary: '#1a1a1a',
      colorBackground: '#ffffff',
      colorText: '#3d3a37',
      colorDanger: '#a33a2e',
      fontFamily: '"Inter", ui-sans-serif, system-ui, sans-serif',
      borderRadius: '4px',
      spacingUnit: '4px',
    },
    rules: {
      '.Input': { borderColor: '#ddd5cb', boxShadow: 'none' },
      '.Input:focus': { borderColor: '#1a1a1a', boxShadow: 'none' },
      '.Label': { color: '#6b6560', fontSize: '13px' },
    },
  }
}

onMounted(async () => {
  try {
    stripe = await loadStripe(props.publicKey)

    if (!stripe) throw new Error('Stripe.js failed to load.')

    elements = props.clientSecret
      ? stripe.elements({ clientSecret: props.clientSecret, appearance: appearance() })
      : stripe.elements({
        mode: 'payment',
        currency: 'cad',
        // Stripe will not mount below its minimum charge. The page keeps the
        // button disabled until a quote exists, so this is a floor rather than
        // a number anyone should ever see.
        amount: Math.max(50, props.amountCents ?? 0),
        appearance: appearance(),
      })

    const element = elements.create('payment', {
      layout: { type: 'tabs', defaultCollapsed: false },
    })

    element.on('ready', () => {
      loading.value = false
      emit('ready')
    })

    element.mount(mountPoint.value!)
  } catch (e: any) {
    // A blocked script or an offline browser must not leave a dead box on the
    // page: say so, and the checkout falls back to its other methods.
    failed.value = e?.message || 'The card form could not be loaded.'
    loading.value = false
    emit('error', failed.value)
  }
})

/**
 * Re-price without remounting. Shipping choice and province both move the
 * total, and an element still holding the old amount would ask Stripe to
 * confirm a figure the order no longer agrees with. Meaningless in existing-
 * intent mode, where the amount is fixed by the order that was already placed.
 */
watch(
  () => props.amountCents,
  (cents) => {
    if (props.clientSecret || cents == null) return

    elements?.update({ amount: Math.max(50, cents) })
  },
)

onBeforeUnmount(() => elements?.getElement('payment')?.destroy())

/** Card details valid? Runs before the order is placed, so a typo costs nothing. */
async function validate(): Promise<string | null> {
  if (!elements) return 'The card form is not ready yet.'

  const { error } = await elements.submit()

  return error ? error.message || 'Please check your card details.' : null
}

/**
 * Hand the card to Stripe.
 *
 * `redirect: 'if_required'` keeps the customer here for an ordinary card and
 * sends them away only when the method genuinely demands it — 3-D Secure, or a
 * wallet that owns its own flow. Either way the order is NOT marked paid by
 * what comes back: that is the webhook's job. A resolved promise here means
 * "the customer finished", not "the money arrived".
 */
async function confirm(clientSecret: string, returnUrl: string): Promise<string | null> {
  if (!stripe || !elements) return 'The card form is not ready yet.'

  const { error } = await stripe.confirmPayment({
    elements,
    // Only in deferred mode. An element built FROM a client secret already
    // knows which payment it belongs to, and Stripe rejects being told twice.
    ...(props.clientSecret ? {} : { clientSecret }),
    confirmParams: { return_url: returnUrl },
    redirect: 'if_required',
  })

  if (!error) return null

  // Stripe's own wording for a decline is better than anything written here
  // and it is localised; only the internal failures get replaced.
  return error.type === 'card_error' || error.type === 'validation_error'
    ? error.message || 'Your card was declined.'
    : 'Something went wrong taking the payment. You have not been charged.'
}

defineExpose({ validate, confirm })
</script>

<template>
  <div>
    <div
      v-if="testMode"
      class="mb-3 flex items-start gap-2 rounded-sm border border-status-warning/30
             bg-status-warning/5 px-3 py-2 text-[12px] leading-relaxed text-status-warning"
    >
      <span class="font-semibold">Test mode.</span>
      <span>No real card is charged. Use Stripe's test card 4242 4242 4242 4242.</span>
    </div>

    <p v-if="loading" class="py-6 text-center text-[13px] text-ink-500">
      Loading secure card form…
    </p>

    <p
      v-else-if="failed"
      class="rounded-sm border border-status-error/30 bg-status-error/5 px-3 py-2.5
             text-[13px] leading-relaxed text-status-error"
    >
      {{ failed }}
    </p>

    <!-- Stripe mounts its iframe here. Deliberately unstyled: anything applied
         to this node would be fighting a cross-origin document that cannot see
         it. Presentation goes through appearance() above. -->
    <div ref="mountPoint" />
  </div>
</template>
