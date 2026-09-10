<script setup lang="ts">
import { Trash2, Minus, Plus } from 'lucide-vue-next'

const cart = useCartStore()

useSeoMeta({ title: 'Cart', robots: 'noindex' })

/**
 * Every figure here came from the server's PriceQuote. This page performs no
 * arithmetic on money — not the subtotal, not the discount, not the shipping
 * gap. That is what keeps the displayed price and the charged price identical.
 */
</script>

<template>
  <div class="container-content pt-8">
    <h1 class="font-display text-[32px] text-ink-900">Your cart</h1>

    <div v-if="cart.isEmpty" class="mt-12 text-center">
      <p class="text-[15px] text-ink-700">Your cart is empty.</p>
      <UiBaseButton to="/women" class="mt-5">Start shopping</UiBaseButton>
    </div>

    <div v-else class="mt-8 grid gap-10 lg:grid-cols-[1fr_360px] lg:gap-14">
      <!-- Lines -->
      <div class="divide-y divide-edge-subtle border-y border-edge-subtle">
        <div v-for="item in cart.items" :key="item.variant_id" class="flex gap-4 py-5">
          <NuxtLink
            :to="`/products/${item.product_slug}`"
            class="size-24 shrink-0 overflow-hidden rounded-sm bg-surface-sunken"
          >
            <img v-if="item.image" :src="item.image" :alt="item.product_name" class="size-full object-cover">
          </NuxtLink>

          <div class="min-w-0 flex-1">
            <div class="flex justify-between gap-4">
              <div class="min-w-0">
                <NuxtLink
                  :to="`/products/${item.product_slug}`"
                  class="text-[15px] font-medium text-ink-900 hover:underline"
                >
                  {{ item.product_name }}
                </NuxtLink>
                <p class="mt-0.5 text-[13px] text-ink-500">{{ item.variant_label }}</p>
                <p class="text-[12px] text-ink-400">{{ item.sku }}</p>
              </div>

              <div class="text-right">
                <p class="tabular text-[15px] font-medium text-ink-900">
                  {{ item.line_total.formatted }}
                </p>
                <!-- Show the saving per line, not just in the summary. -->
                <p v-if="item.line_discount.cents > 0" class="tabular text-[12px] text-ink-400 line-through">
                  {{ item.unit_retail.formatted }} each
                </p>
                <p v-if="item.line_discount.cents > 0" class="tabular text-[12px] text-sage">
                  {{ item.unit_price.formatted }} each
                </p>
              </div>
            </div>

            <div class="mt-3 flex items-center gap-3">
              <div class="flex h-9 items-center rounded-sm border border-edge">
                <button
                  class="grid size-9 place-items-center text-ink-700 disabled:text-ink-400"
                  :disabled="cart.loading"
                  aria-label="Decrease quantity"
                  @click="cart.updateQty(item.variant_id, item.qty - 1)"
                >
                  <Minus :size="14" />
                </button>
                <span class="tabular w-8 text-center text-[14px]">{{ item.qty }}</span>
                <button
                  class="grid size-9 place-items-center text-ink-700 disabled:text-ink-400"
                  :disabled="cart.loading || item.qty >= item.available"
                  aria-label="Increase quantity"
                  @click="cart.updateQty(item.variant_id, item.qty + 1)"
                >
                  <Plus :size="14" />
                </button>
              </div>

              <button
                class="flex items-center gap-1.5 text-[13px] text-ink-500 hover:text-status-error"
                :disabled="cart.loading"
                @click="cart.remove(item.variant_id)"
              >
                <Trash2 :size="14" /> Remove
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Summary -->
      <aside class="lg:sticky lg:top-32 lg:self-start">
        <ShopTierProgress :quote="cart.quote" class="mb-5" />

        <div class="rounded-sm border border-edge-subtle p-5">
          <h2 class="font-body text-[12px] font-semibold tracking-[0.06em] text-ink-900 uppercase">
            Order summary
          </h2>

          <dl class="mt-4 space-y-2.5 text-[14px]">
            <div class="flex justify-between">
              <dt class="text-ink-700">Subtotal</dt>
              <dd class="tabular text-ink-900">{{ cart.quote?.totals.retail_subtotal.formatted }}</dd>
            </div>

            <div v-if="cart.quote && cart.quote.discount_cents > 0" class="flex justify-between">
              <dt class="text-sage">
                Wholesale discount
                <span v-if="cart.tier" class="text-ink-500">· {{ cart.tier.name }}</span>
              </dt>
              <dd class="tabular text-sage">−{{ cart.quote.totals.discount.formatted }}</dd>
            </div>

            <div class="flex justify-between">
              <dt class="text-ink-700">Shipping</dt>
              <dd class="text-[13px] text-ink-500">
                <span v-if="cart.freeShipping?.qualifies" class="text-sage">Free</span>
                <span v-else>Calculated at checkout</span>
              </dd>
            </div>

            <div class="flex justify-between">
              <dt class="text-ink-700">Tax</dt>
              <dd class="text-[13px] text-ink-500">Calculated at checkout</dd>
            </div>

            <div class="flex justify-between border-t border-edge-subtle pt-3 text-[16px] font-medium">
              <dt class="text-ink-900">Total</dt>
              <dd class="tabular text-ink-900">{{ cart.quote?.totals.subtotal.formatted }}</dd>
            </div>
          </dl>

          <p
            v-if="cart.freeShipping && !cart.freeShipping.qualifies && cart.freeShipping.gap"
            class="mt-3 text-[13px] text-ink-500"
          >
            Add <span class="tabular font-medium text-ink-900">{{ cart.freeShipping.gap.formatted }}</span>
            for free shipping.
          </p>

          <UiBaseButton block size="lg" class="mt-5" to="/checkout">
            Proceed to checkout
          </UiBaseButton>

          <p class="mt-3 text-center text-[12px] text-ink-400">
            Taxes and shipping calculated at checkout. Prices in CAD.
          </p>
        </div>
      </aside>
    </div>
  </div>
</template>
