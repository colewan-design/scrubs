<script setup lang="ts">
import { Check, Package, Store } from 'lucide-vue-next'
import type { Order } from '~/composables/useApi'

/**
 * Order confirmation and, later, the order's own page (§7, §8).
 *
 * Order numbers are sequential and therefore guessable, so the API proves
 * ownership on every read: either the signed-in customer owns it, or the email
 * it was placed with is supplied. That is why the confirmation link carries the
 * address — a guest has no session to prove anything with.
 */
const route = useRoute()
const api = useApi()

const { data, error } = await useAsyncData(`order-${route.params.number}`, () =>
  api.get<{ order: Order }>(`/orders/${route.params.number}`, {
    email: typeof route.query.email === 'string' ? route.query.email : undefined,
  }),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Order not found', fatal: true })
}

const order = computed(() => data.value!.order)
const isPickup = computed(() => order.value.fulfillment_type === 'pickup')

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
            <div class="flex justify-between gap-4 border-t border-edge-subtle pt-3 text-[16px]">
              <dt class="font-medium text-ink-900">Paid</dt>
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
