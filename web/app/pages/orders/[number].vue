<script setup lang="ts">
import { AlertCircle, Check, Loader, Package, Store } from 'lucide-vue-next'

/**
 * Order confirmation and, later, the order's own page (§7, §8).
 *
 * Order numbers are sequential and therefore guessable, so the API proves
 * ownership on every read: either the signed-in customer owns it, or the email
 * it was placed with is supplied. That is why the confirmation link carries the
 * address — a guest has no session to prove anything with.
 */
import type { Order, PaymentSession } from '~/composables/useApi'

const route = useRoute()
const api = useApi()

const emailParam = computed(() =>
  typeof route.query.email === 'string' ? route.query.email : undefined,
)

const { data, error, refresh } = await useAsyncData(`order-${route.params.number}`, () =>
  api.get<{ order: Order }>(`/orders/${route.params.number}`, { email: emailParam.value }),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Order not found', fatal: true })
}

const order = computed(() => data.value!.order)
const isPickup = computed(() => order.value.fulfillment_type === 'pickup')
const isPaid = computed(() => order.value.payment_status !== 'pending')

/** Card orders are the only ones this page can do anything about. */
const paidByCard = computed(() => order.value.payment_method === 'stripe')

/**
 * WHY THIS PAGE WAITS INSTEAD OF DECLARING VICTORY.
 *
 * Stripe's redirect comes back with `redirect_status=succeeded` the instant the
 * customer authorises, but the order is not paid until the webhook lands a
 * moment later — and the webhook, not the browser, is what marks it. So a card
 * order that is still Pending Payment right after a successful authorisation is
 * normal, not broken. The page polls briefly and says "confirming" rather than
 * either lying about the status or alarming someone whose payment is fine.
 *
 * If the wait elapses the order is left as it is: unpaid, holding its stock,
 * with a retry available. Nothing here ever changes a payment status.
 */
const returnedFromStripe = computed(() => route.query.redirect_status === 'succeeded')
const cardFailed = computed(
  () => route.query.payment === 'failed' || route.query.redirect_status === 'failed',
)
const providerUnavailable = computed(() => route.query.payment === 'unavailable')

const confirming = ref(false)

onMounted(async () => {
  if (!returnedFromStripe.value || isPaid.value) return

  confirming.value = true

  // Ten tries, two seconds apart. Long enough for an ordinary webhook round
  // trip, short enough that nobody sits watching a spinner over a broken one.
  for (let attempt = 0; attempt < 10; attempt++) {
    await new Promise((resolve) => setTimeout(resolve, 2000))
    await refresh()

    if (isPaid.value) break
  }

  confirming.value = false
})

// --- retry -----------------------------------------------------------------

/**
 * Pay an order that exists but was never settled — a declined card, a closed
 * tab, a provider that was down when the order was placed.
 *
 * Deliberately a retry against the SAME order rather than an invitation to
 * check out again: the original is still holding its stock, and a second order
 * would both double-reserve it and leave the first to be swept away.
 */
const retrySession = ref<PaymentSession | null>(null)
const retryForm = ref<{
  validate: () => Promise<string | null>
  confirm: (clientSecret: string, returnUrl: string) => Promise<string | null>
} | null>(null)

const retryError = ref('')
const retryBusy = ref(false)
const retryReady = ref(false)

const canRetry = computed(() => paidByCard.value && !isPaid.value && !confirming.value)

async function startRetry() {
  retryError.value = ''
  retryBusy.value = true

  try {
    const { payment } = await api.post<{ payment: PaymentSession }>(
      `/orders/${order.value.order_number}/payment`,
      // A guest has no session, so the address it was placed with is the proof
      // of ownership — the same rule the order lookup uses.
      { email: emailParam.value },
    )

    retrySession.value = payment
  } catch (e: any) {
    retryError.value = e?.data?.message || 'We could not reopen this payment. Please contact us.'
  } finally {
    retryBusy.value = false
  }
}

async function submitRetry() {
  retryError.value = ''

  const invalid = await retryForm.value?.validate()

  if (invalid) {
    retryError.value = invalid

    return
  }

  retryBusy.value = true

  try {
    const problem = await retryForm.value?.confirm(
      retrySession.value!.client_secret,
      `${window.location.origin}${route.fullPath.split('?')[0]}?email=${encodeURIComponent(order.value.email)}`,
    )

    if (problem) {
      retryError.value = problem

      return
    }

    // Authorised. The webhook still has to land, so fall into the same
    // confirming-and-polling wait the redirect path uses.
    retrySession.value = null
    confirming.value = true

    for (let attempt = 0; attempt < 10; attempt++) {
      await new Promise((resolve) => setTimeout(resolve, 2000))
      await refresh()

      if (isPaid.value) break
    }

    confirming.value = false
  } finally {
    retryBusy.value = false
  }
}

useSeoMeta({ title: `Order ${route.params.number}`, robots: 'noindex' })
</script>

<template>
  <div class="container-content pt-10 pb-20">
    <div class="max-w-[720px]">
      <p class="inline-flex items-center gap-2 text-[13px] text-sage">
        <Check :size="15" aria-hidden="true" /> Order placed
      </p>

      <h1 class="mt-2 font-display text-[32px] text-ink-900">
        Thank you — order {{ order.order_number }}
      </h1>

      <p class="mt-3 max-w-[56ch] text-[15px] leading-relaxed text-ink-700">
        A confirmation has been sent to <strong>{{ order.email }}</strong>. Your
        order is currently <strong>{{ order.status_label }}</strong
        >; we will email you as soon as that changes.
      </p>

      <!-- Payment, when there is something to say about it. A settled order
           says nothing here — the status line above already covers it. -->
      <div
        v-if="confirming"
        class="mt-6 flex items-start gap-3 rounded-sm border border-edge bg-surface-sunken px-5 py-4"
      >
        <Loader :size="18" class="mt-0.5 shrink-0 animate-spin text-ink-500" aria-hidden="true" />
        <div class="text-[14px]">
          <p class="font-medium text-ink-900">Confirming your payment…</p>
          <p class="mt-0.5 text-[13px] leading-relaxed text-ink-500">
            Your card has been authorised. We are waiting for the final confirmation from our
            payment provider — this usually takes a few seconds. You can safely leave this page;
            your receipt will arrive by email.
          </p>
        </div>
      </div>

      <div
        v-else-if="canRetry"
        class="mt-6 rounded-sm border border-status-warning/30 bg-status-warning/5 px-5 py-4"
      >
        <div class="flex items-start gap-3">
          <AlertCircle
            :size="18"
            class="mt-0.5 shrink-0 text-status-warning"
            aria-hidden="true"
          />
          <div class="min-w-0 text-[14px]">
            <p class="font-medium text-ink-900">
              {{
                providerUnavailable
                  ? 'We could not reach our payment provider'
                  : cardFailed
                    ? 'Your payment was not completed'
                    : 'This order is waiting for payment'
              }}
            </p>
            <p class="mt-0.5 text-[13px] leading-relaxed text-ink-700">
              Your order is saved and your items are held — nothing has been charged. You can
              finish paying below without placing the order again.
            </p>

            <!-- Mounted only on request. Loading Stripe.js for everyone who
                 lands on a receipt would be a third-party script on a page
                 that almost never needs one. -->
            <div v-if="retrySession" class="mt-4">
              <ClientOnly>
                <CheckoutStripePayment
                  ref="retryForm"
                  :public-key="retrySession.public_key"
                  :client-secret="retrySession.client_secret"
                  :test-mode="retrySession.test_mode"
                  @ready="retryReady = true"
                  @error="retryError = $event"
                />
              </ClientOnly>

              <UiBaseButton
                class="mt-3"
                :loading="retryBusy"
                :disabled="retryBusy || !retryReady"
                @click="submitRetry"
              >
                Pay {{ order.totals.grand_total.formatted }}
              </UiBaseButton>
            </div>

            <UiBaseButton
              v-else
              class="mt-3"
              :loading="retryBusy"
              :disabled="retryBusy"
              @click="startRetry"
            >
              Complete payment
            </UiBaseButton>

            <p v-if="retryError" class="mt-2 text-[13px] text-status-error">{{ retryError }}</p>
          </div>
        </div>
      </div>

      <!-- Status -->
      <div class="mt-8 flex items-center gap-3 rounded-sm border border-edge-subtle bg-surface-warm px-5 py-4">
        <component :is="isPickup ? Store : Package" :size="18" class="text-ink-500" aria-hidden="true" />
        <div class="text-[14px]">
          <p class="font-medium text-ink-900">
            {{ isPickup ? 'Local pickup' : 'Shipping' }} · {{ order.status_label }}
          </p>
          <p v-if="order.timeline?.length" class="text-[13px] text-ink-500">
            {{ order.timeline[order.timeline.length - 1]?.note }}
          </p>
        </div>
      </div>

      <!-- Items -->
      <section class="mt-10">
        <h2 class="text-[12px] font-semibold tracking-[0.08em] text-ink-500 uppercase">
          Items
        </h2>
        <ul class="mt-4 divide-y divide-edge-subtle border-y border-edge-subtle">
          <li v-for="item in order.items ?? []" :key="item.variant_sku" class="flex gap-4 py-4">
            <div class="min-w-0 flex-1">
              <p class="text-[15px] text-ink-900">{{ item.product_name }}</p>
              <p class="text-[13px] text-ink-500">
                {{ item.variant_label }} · {{ item.variant_sku }} · ×{{ item.qty }}
              </p>
              <p v-if="item.line_discount.cents > 0" class="text-[13px] text-sage">
                {{ item.pricing_tier_name }} · {{ item.unit_price.formatted }} each
                <span class="text-ink-400 line-through">{{ item.unit_retail.formatted }}</span>
              </p>
            </div>
            <p class="tabular shrink-0 text-[15px] text-ink-900">{{ item.line_total.formatted }}</p>
          </li>
        </ul>
      </section>

      <div class="mt-10 grid gap-10 sm:grid-cols-2">
        <!-- Totals -->
        <section>
          <h2 class="text-[12px] font-semibold tracking-[0.08em] text-ink-500 uppercase">
            Total
          </h2>
          <dl class="mt-4 space-y-2 text-[14px]">
            <div class="flex justify-between gap-4">
              <dt class="text-ink-700">Subtotal</dt>
              <dd class="tabular text-ink-900">{{ order.totals.subtotal.formatted }}</dd>
            </div>
            <div v-if="order.totals.discount.cents > 0" class="flex justify-between gap-4">
              <dt class="text-sage">
                Wholesale saving
                <span v-if="order.pricing_tier_name" class="text-ink-500">· {{ order.pricing_tier_name }}</span>
              </dt>
              <dd class="tabular text-sage">−{{ order.totals.discount.formatted }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-ink-700">{{ isPickup ? 'Pickup' : 'Shipping' }}</dt>
              <dd class="tabular text-ink-900">{{ order.totals.shipping.formatted }}</dd>
            </div>
            <div v-for="tax in order.taxes ?? []" :key="tax.label" class="flex justify-between gap-4">
              <dt class="text-ink-700">{{ tax.label }}</dt>
              <dd class="tabular text-ink-900">{{ tax.amount.formatted }}</dd>
            </div>
            <!-- "Paid" is a claim, not a label. An order still awaiting
                 payment says Total, because nothing has been charged yet. -->
            <div class="flex justify-between gap-4 border-t border-edge-subtle pt-3 text-[16px]">
              <dt class="font-medium text-ink-900">{{ isPaid ? 'Paid' : 'Total' }}</dt>
              <dd class="tabular font-medium text-ink-900">{{ order.totals.grand_total.formatted }}</dd>
            </div>
          </dl>

          <!-- §6: a receipt that charges GST/HST has to carry the seller's
               registration number. Absent when the business held none at the
               time, rather than printed blank. -->
          <p v-if="order.tax_registration" class="mt-4 text-[12px] text-ink-400">
            BulkScrubs Direct · GST/HST registration {{ order.tax_registration }}
          </p>
        </section>

        <!-- Address -->
        <section v-if="order.shipping_address">
          <h2 class="text-[12px] font-semibold tracking-[0.08em] text-ink-500 uppercase">
            Shipping to
          </h2>
          <address class="mt-4 space-y-0.5 text-[14px] not-italic text-ink-700">
            <p v-for="line in order.shipping_address.lines" :key="line">{{ line }}</p>
          </address>
        </section>
      </div>

      <div class="mt-12 flex flex-wrap gap-2">
        <UiBaseButton to="/" variant="secondary">Continue shopping</UiBaseButton>
        <UiBaseButton to="/account/orders" variant="tertiary">Your orders</UiBaseButton>
      </div>
    </div>
  </div>
</template>
