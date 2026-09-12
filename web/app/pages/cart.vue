<script setup lang="ts">
import {
  ArrowLeft,
  ArrowRight,
  ChevronDown,
  FileText,
  Info,
  Lock,
  MapPin,
  Minus,
  Plus,
  ShoppingBag,
  Truck,
} from 'lucide-vue-next'
import type { ProductCard } from '~/composables/useApi'

/**
 * The cart, rebuilt to the redesign: two threshold meters across the top, the
 * lines as a real table, a sticky summary, the delivery choice made here rather
 * than discovered at checkout, and a recommendation strip to close.
 *
 * Every figure here came from the server's PriceQuote. This page performs no
 * arithmetic on money — not the subtotal, not the discount, not the shipping
 * gap. That is what keeps the displayed price and the charged price identical.
 */
const api = useApi()
const cart = useCartStore()

/** Carried into checkout in a cookie, so the choice made here survives the trip. */
const fulfillment = useFulfillmentPreference()

useSeoMeta({ title: 'Cart', robots: 'noindex' })

// Pickup is an admin setting; the API returns null for `pickup` when the client
// has it switched off, and a one-option question is no question at all.
const { data: store } = await useAsyncData('store-cart', () =>
  api.get<{
    pickup: { address: string | null; hours: string | null; lead_time: string | null } | null
  }>('/content/store'),
)
const pickup = computed(() => store.value?.pickup ?? null)

// A cart carrying a pickup preference the store no longer offers would send a
// shopper to checkout asking for something it cannot fulfil.
watch(pickup, (value) => {
  if (!value && fulfillment.value === 'pickup') fulfillment.value = 'ship'
}, { immediate: true })

/**
 * "You might also like". No recommendations endpoint exists, so this is the
 * catalogue's own order with anything already in the basket removed —
 * over-fetched to absorb the filtering rather than becoming a new route.
 */
const RECOMMEND_LIMIT = 6
const { data: recommendedResponse } = await useAsyncData('cart-recommended', () =>
  api.get<{ data: ProductCard[] }>('/products', { per_page: RECOMMEND_LIMIT + 4 }),
)

const recommended = computed(() => {
  const inCart = new Set(cart.items.map((i) => i.product_slug))

  return (recommendedResponse.value?.data ?? [])
    .filter((p) => !inCart.has(p.slug))
    .slice(0, RECOMMEND_LIMIT)
})

/**
 * TODO(promotions): drawn because the redesign draws it, and deliberately
 * honest. There is no promotions API — no codes exist, no table, no admin
 * screen — so this rejects what it is given rather than pretending to apply a
 * discount the server would never charge. Wiring it up is a small API addition
 * whenever the client wants one.
 */
const promoOpen = ref(false)
const promoCode = ref('')
const promoMessage = ref('')

function applyPromo() {
  const code = promoCode.value.trim()
  promoMessage.value = code ? `${code} isn't a valid code.` : ''
}

// Spelled out for a screen reader as well as drawn as an ⓘ: the icon alone
// tells a non-sighted shopper nothing about why a figure is missing.
const hints = {
  discount: 'Wholesale pricing applies automatically once your order reaches a tier threshold.',
  shipping: 'Shipping is rated at checkout, once we know where the order is going.',
  tax: 'Sales tax is calculated at checkout from your province.',
}

/** The five-column line grid, shared by the header row and every line. */
const ROW = 'md:grid md:grid-cols-[minmax(0,2.4fr)_1.2fr_0.9fr_1fr_0.9fr] md:items-center md:gap-4'
</script>

<template>
  <div class="container-content pt-8">
    <!-- Header -->
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="font-display text-[32px] text-ink-900 sm:text-[38px]">Your Cart</h1>
        <p class="mt-1 text-[14px] text-ink-500">
          <template v-if="!cart.isEmpty">
            {{ cart.itemCount }} {{ cart.itemCount === 1 ? 'item' : 'items' }} ·
          </template>
          Review your items and proceed to checkout.
        </p>
      </div>

      <UiBaseButton to="/products" variant="secondary" class="shrink-0">
        <ArrowLeft :size="15" aria-hidden="true" />
        Continue Shopping
      </UiBaseButton>
    </div>

    <!-- Empty -->
    <div
      v-if="cart.isEmpty"
      class="mt-10 flex flex-col items-center rounded-md border border-edge-subtle bg-surface-sunken px-6 py-16 text-center"
    >
      <span class="grid size-14 place-items-center rounded-full bg-white text-ink-400">
        <ShoppingBag :size="22" aria-hidden="true" />
      </span>
      <p class="mt-4 text-[16px] font-medium text-ink-900">Your cart is empty</p>
      <p class="mt-1 max-w-[46ch] text-[14px] text-ink-500">
        Add a few styles and the wholesale and free-shipping thresholds will track themselves here.
      </p>
      <UiBaseButton to="/products" class="mt-6">Start shopping</UiBaseButton>
    </div>

    <template v-else>
      <ShopCartProgress :quote="cart.quote" class="mt-6" />

      <div class="mt-8 grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_380px] lg:gap-10">
        <!-- ---------------------------------------------------------- lines -->
        <div class="min-w-0 space-y-8">
          <div class="overflow-hidden rounded-md border border-edge-subtle">
            <div
              class="hidden border-b border-edge-subtle bg-surface-sunken px-5 py-3 text-[11px]
                     font-semibold tracking-[0.08em] text-ink-500 uppercase"
              :class="ROW"
              aria-hidden="true"
            >
              <span>Product</span>
              <span>Details</span>
              <span>Price</span>
              <span>Quantity</span>
              <span>Total</span>
            </div>

            <ul class="divide-y divide-edge-subtle">
              <li
                v-for="item in cart.items"
                :key="item.variant_id"
                class="px-4 py-5 md:px-5"
                :class="ROW"
              >
                <!-- 1. Product -->
                <div class="flex gap-4">
                  <NuxtLink
                    :to="`/products/${item.product_slug}`"
                    class="aspect-4/5 w-20 shrink-0 overflow-hidden rounded-sm bg-surface-sunken"
                  >
                    <img
                      v-if="item.image"
                      :src="item.image"
                      :alt="item.product_name"
                      class="size-full object-cover"
                    >
                  </NuxtLink>

                  <div class="min-w-0 self-center">
                    <NuxtLink
                      :to="`/products/${item.product_slug}`"
                      class="text-[14px] font-medium text-ink-900 hover:underline"
                    >
                      {{ item.product_name }}
                    </NuxtLink>
                    <p class="mt-0.5 text-[12px] text-ink-400">SKU {{ item.sku }}</p>
                  </div>
                </div>

                <!-- 2. Details -->
                <div class="mt-3 text-[13px] text-ink-700 md:mt-0">
                  <p v-if="item.color">Colour: {{ item.color }}</p>
                  <p v-if="item.size">Size: {{ item.size }}</p>
                  <p v-if="item.secondary_size">Length: {{ item.secondary_size }}</p>
                  <!-- Neither part is named on this variant — show the label as
                       the server assembled it rather than guess at it. -->
                  <p v-if="!item.color && !item.size">{{ item.variant_label }}</p>

                  <button
                    class="mt-1 text-[13px] text-ink-500 underline underline-offset-4 hover:text-status-error disabled:opacity-60"
                    :disabled="cart.loading"
                    @click="cart.remove(item.variant_id)"
                  >
                    Remove
                  </button>
                </div>

                <!-- 3–5. Price · Quantity · Total. One row on a phone; three
                     grid cells from md, where `contents` dissolves the wrapper. -->
                <div class="mt-4 flex items-center justify-between gap-3 md:mt-0 md:contents">
                  <div>
                    <p class="tabular text-[14px] text-ink-900">
                      {{ item.unit_price.currency }} {{ item.unit_price.formatted }}
                    </p>
                    <p
                      v-if="item.line_discount.cents > 0"
                      class="tabular text-[12px] text-ink-400 line-through"
                    >
                      {{ item.unit_retail.formatted }}
                    </p>
                  </div>

                  <div class="flex h-10 items-center rounded-sm border border-edge md:justify-self-start">
                    <button
                      class="grid size-9 place-items-center text-ink-700 disabled:text-ink-400"
                      :disabled="cart.loading"
                      :aria-label="`Decrease quantity of ${item.product_name}`"
                      @click="cart.updateQty(item.variant_id, item.qty - 1)"
                    >
                      <Minus :size="14" />
                    </button>
                    <span class="tabular w-9 border-x border-edge text-center text-[14px] leading-9">
                      {{ item.qty }}
                    </span>
                    <button
                      class="grid size-9 place-items-center text-ink-700 disabled:text-ink-400"
                      :disabled="cart.loading || item.qty >= item.available"
                      :aria-label="`Increase quantity of ${item.product_name}`"
                      @click="cart.updateQty(item.variant_id, item.qty + 1)"
                    >
                      <Plus :size="14" />
                    </button>
                  </div>

                  <p class="tabular text-[14px] font-semibold text-ink-900">
                    {{ item.line_total.currency }} {{ item.line_total.formatted }}
                  </p>
                </div>
              </li>
            </ul>
          </div>

          <!-- ------------------------------------------------- fulfillment -->
          <section
            v-if="pickup"
            class="rounded-md border border-edge-subtle p-5"
            aria-labelledby="fulfillment-heading"
          >
            <h2 id="fulfillment-heading" class="font-display text-[22px] text-ink-900">
              Choose how you'll get your order
            </h2>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
              <label
                class="flex cursor-pointer items-center gap-3 rounded-md border p-4 transition-colors"
                :class="fulfillment === 'ship'
                  ? 'border-ink-900 bg-surface-sunken'
                  : 'border-edge-subtle hover:border-edge'"
              >
                <input
                  v-model="fulfillment"
                  type="radio"
                  name="fulfillment"
                  value="ship"
                  class="size-4 shrink-0 accent-ink-900"
                >
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-surface-warm text-ink-700">
                  <Truck :size="18" aria-hidden="true" />
                </span>
                <span class="min-w-0">
                  <span class="block text-[14px] font-medium text-ink-900">Ship to my address</span>
                  <span class="block text-[13px] leading-snug text-ink-500">
                    Delivery across Canada. Shipping calculated at checkout.
                  </span>
                </span>
              </label>

              <label
                class="flex cursor-pointer items-center gap-3 rounded-md border p-4 transition-colors"
                :class="fulfillment === 'pickup'
                  ? 'border-ink-900 bg-surface-sunken'
                  : 'border-edge-subtle hover:border-edge'"
              >
                <input
                  v-model="fulfillment"
                  type="radio"
                  name="fulfillment"
                  value="pickup"
                  class="size-4 shrink-0 accent-ink-900"
                >
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-surface-warm text-ink-700">
                  <MapPin :size="18" aria-hidden="true" />
                </span>
                <span class="min-w-0">
                  <span class="block text-[14px] font-medium text-ink-900">Pick up in store</span>
                  <span class="block text-[13px] leading-snug text-ink-500">
                    <!-- The address is client-supplied; say "our location" until it arrives. -->
                    {{ pickup.address || 'Collect from our Canadian location.' }}
                    <template v-if="pickup.lead_time"> {{ pickup.lead_time }}</template>
                  </span>
                </span>
              </label>
            </div>
          </section>
        </div>

        <!-- -------------------------------------------------------- summary -->
        <aside class="space-y-4 lg:sticky lg:top-32 lg:self-start">
          <div class="rounded-md border border-edge-subtle p-5">
            <h2 class="font-display text-[22px] text-ink-900">Order Summary</h2>

            <dl class="mt-4 space-y-3 text-[14px]">
              <div class="flex justify-between gap-4">
                <dt class="text-ink-700">
                  Subtotal ({{ cart.itemCount }} {{ cart.itemCount === 1 ? 'item' : 'items' }})
                </dt>
                <dd class="tabular text-ink-900">
                  {{ cart.quote?.totals.retail_subtotal.currency }}
                  {{ cart.quote?.totals.retail_subtotal.formatted }}
                </dd>
              </div>

              <div class="flex justify-between gap-4">
                <dt class="flex items-center gap-1.5 text-ink-700">
                  Wholesale discount
                  <span v-if="cart.tier" class="text-ink-500">· {{ cart.tier.name }}</span>
                  <Info :size="13" class="shrink-0 text-ink-400" :title="hints.discount" aria-hidden="true" />
                  <span class="sr-only">{{ hints.discount }}</span>
                </dt>
                <dd
                  v-if="cart.quote && cart.quote.discount_cents > 0"
                  class="tabular font-medium text-sage"
                >
                  −{{ cart.quote.totals.discount.currency }} {{ cart.quote.totals.discount.formatted }}
                </dd>
                <dd v-else class="text-ink-400">—</dd>
              </div>

              <div class="flex justify-between gap-4">
                <dt class="flex items-center gap-1.5 text-ink-700">
                  Estimated shipping
                  <Info :size="13" class="shrink-0 text-ink-400" :title="hints.shipping" aria-hidden="true" />
                  <span class="sr-only">{{ hints.shipping }}</span>
                </dt>
                <dd class="text-[13px] text-ink-500">
                  <span v-if="fulfillment === 'pickup'">No charge · pickup</span>
                  <span v-else-if="cart.freeShipping?.qualifies" class="font-medium text-sage">Free</span>
                  <span v-else>Calculated at checkout</span>
                </dd>
              </div>

              <div class="flex justify-between gap-4">
                <dt class="flex items-center gap-1.5 text-ink-700">
                  Estimated taxes
                  <Info :size="13" class="shrink-0 text-ink-400" :title="hints.tax" aria-hidden="true" />
                  <span class="sr-only">{{ hints.tax }}</span>
                </dt>
                <dd class="text-[13px] text-ink-500">Calculated at checkout</dd>
              </div>

              <div class="flex justify-between gap-4 border-t border-edge-subtle pt-3.5 text-[17px] font-medium">
                <dt class="text-ink-900">Estimated total</dt>
                <dd class="tabular text-ink-900">
                  {{ cart.quote?.totals.subtotal.currency }}
                  {{ cart.quote?.totals.subtotal.formatted }}
                </dd>
              </div>
            </dl>

            <UiBaseButton block size="lg" class="mt-5" to="/checkout">
              Proceed to Checkout
              <ArrowRight :size="16" aria-hidden="true" />
            </UiBaseButton>

            <p class="mt-3 flex items-center justify-center gap-1.5 text-[12px] text-ink-500">
              <Lock :size="12" aria-hidden="true" />
              Secure checkout. Your information is protected.
            </p>

            <!-- Promo code. See the TODO above: nothing here can be redeemed yet. -->
            <div class="mt-4 border-t border-edge-subtle pt-4">
              <button
                class="flex w-full items-center justify-between text-[14px] text-ink-900"
                :aria-expanded="promoOpen"
                aria-controls="promo-panel"
                @click="promoOpen = !promoOpen"
              >
                Have a promo code?
                <ChevronDown
                  :size="16"
                  class="text-ink-500 transition-transform"
                  :class="promoOpen && 'rotate-180'"
                  aria-hidden="true"
                />
              </button>

              <form v-if="promoOpen" id="promo-panel" class="mt-3 flex gap-2" @submit.prevent="applyPromo">
                <input
                  v-model="promoCode"
                  type="text"
                  aria-label="Promo code"
                  placeholder="Enter code"
                  class="h-10 min-w-0 flex-1 rounded-sm border border-edge px-3 text-[14px] text-ink-900
                         placeholder:text-ink-400 focus:border-edge-strong focus:outline-none"
                >
                <UiBaseButton type="submit" variant="secondary" size="sm" class="h-10 shrink-0">
                  Apply
                </UiBaseButton>
              </form>
              <p v-if="promoOpen && promoMessage" class="mt-2 text-[12px] text-status-error" role="status">
                {{ promoMessage }}
              </p>
            </div>
          </div>

          <!-- Larger orders route to a human rather than through the cart. -->
          <div class="flex gap-3.5 rounded-md border border-edge-subtle bg-surface-sunken p-5">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-white text-ink-700">
              <FileText :size="18" aria-hidden="true" />
            </span>
            <div class="min-w-0">
              <p class="text-[14px] font-semibold text-ink-900">Need a quote or purchase order?</p>
              <p class="mt-0.5 text-[13px] leading-snug text-ink-700">
                For larger orders or institutional purchases, contact our team for a custom quote.
              </p>
              <NuxtLink
                to="/contact"
                class="mt-2 inline-flex items-center gap-1.5 text-[13px] font-medium text-ink-900
                       underline-offset-4 hover:underline"
              >
                Contact Sales
                <ArrowRight :size="14" aria-hidden="true" />
              </NuxtLink>
            </div>
          </div>
        </aside>
      </div>

      <!-- ------------------------------------------------- recommendations -->
      <section v-if="recommended.length" class="mt-14 border-t border-edge-subtle pt-10">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h2 class="font-display text-[26px] text-ink-900">You might also like</h2>
            <p class="mt-1 text-[13px] text-ink-500">Complete your look with these popular items.</p>
          </div>
          <NuxtLink
            to="/products"
            class="flex items-center gap-2 text-[13px] font-medium text-ink-900 underline-offset-4 hover:underline"
          >
            View All Products
            <ArrowRight :size="15" aria-hidden="true" />
          </NuxtLink>
        </div>

        <div class="mt-7 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
          <ShopProductCard v-for="item in recommended" :key="item.id" :product="item" />
        </div>
      </section>
    </template>
  </div>
</template>
