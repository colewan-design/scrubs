<script setup lang="ts">
import {
  ArrowRight,
  CheckCircle2,
  Clock,
  ExternalLink,
  MapPin,
  Package,
  RotateCcw,
  Settings,
  ShoppingCart,
  Store,
  Truck,
  User,
  Users,
  XCircle,
} from 'lucide-vue-next'
import type { Component } from 'vue'
import type { Order, OrderItemLine, SavedAddress } from '~/composables/useApi'

/** The customer's order history (§8). */
definePageMeta({ middleware: 'auth' })

const api = useApi()
const auth = useAuthStore()
const cart = useCartStore()

const { data } = await useAsyncData('account-orders', async () => {
  const [orders, addresses] = await Promise.all([
    api.get<{ data: Order[] }>('/orders'),
    api.get<{ data: SavedAddress[] }>('/account/addresses'),
  ])

  return { orders: orders.data ?? [], addresses: addresses.data ?? [] }
})

const orders = computed(() => data.value?.orders ?? [])

const defaultAddress = computed(
  () => (data.value?.addresses ?? []).find((a) => a.is_default_shipping)
    ?? (data.value?.addresses ?? [])[0]
    ?? null,
)

/**
 * Status chips.
 *
 * Colour is never the only thing carrying the status — every chip states it in
 * words and takes an icon, which is what WCAG 1.4.1 asks for. Sage stays out of
 * this entirely: on this site sage means wholesale and nothing else, so the
 * status palette is the status tokens.
 */
const STATUS_STYLES: Record<string, { icon: Component; chip: string }> = {
  pending_payment: { icon: Clock, chip: 'bg-status-warning/10 text-status-warning' },
  paid: { icon: CheckCircle2, chip: 'bg-status-info/10 text-status-info' },
  processing: { icon: Settings, chip: 'bg-status-info/10 text-status-info' },
  ready_for_pickup: { icon: Store, chip: 'bg-status-info/10 text-status-info' },
  shipped: { icon: Truck, chip: 'bg-status-info/10 text-status-info' },
  completed: { icon: CheckCircle2, chip: 'bg-status-success/10 text-status-success' },
  cancelled: { icon: XCircle, chip: 'bg-surface-warm-deep text-ink-500' },
  refunded: { icon: RotateCcw, chip: 'bg-status-error/10 text-status-error' },
}

const FALLBACK_STYLE = { icon: Package, chip: 'bg-surface-warm text-ink-700' }

const styleFor = (status: string) => STATUS_STYLES[status] ?? FALLBACK_STYLE

/**
 * The filter rail.
 *
 * Built from the statuses this customer actually has rather than from a fixed
 * list, so it can never offer an empty tab — and never hide an order behind a
 * tab nobody drew. Labels come from the server, which is where the client's
 * status vocabulary is defined.
 */
const STATUS_ORDER = [
  'pending_payment', 'paid', 'processing', 'ready_for_pickup',
  'shipped', 'completed', 'cancelled', 'refunded',
]

const tabs = computed(() => {
  const present = new Map(orders.value.map((o) => [o.status, o.status_label]))

  return [
    { key: 'all', label: 'All Orders' },
    ...STATUS_ORDER.filter((s) => present.has(s)).map((s) => ({ key: s, label: present.get(s)! })),
  ]
})

const activeTab = ref('all')

const visibleOrders = computed(() =>
  activeTab.value === 'all'
    ? orders.value
    : orders.value.filter((o) => o.status === activeTab.value),
)

// --- per-order presentation ------------------------------------------------

/** "Oct 14, 2024". Null dates simply produce nothing to render. */
function formatDate(value: string | null | undefined) {
  if (!value) return null

  return new Date(value).toLocaleDateString('en-CA', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}

const itemCount = (order: Order) => (order.items ?? []).reduce((n, i) => n + i.qty, 0)
const thumbs = (order: Order) => (order.items ?? []).slice(0, 3)
const moreLines = (order: Order) => Math.max(0, (order.items?.length ?? 0) - 3)

/** The first shipment that can actually be followed. */
const trackable = (order: Order) =>
  (order.shipments ?? []).find((s) => s.tracking_number) ?? null

/**
 * One line of plain English under the chip. Everything here is a fact the order
 * carries — no estimated delivery dates, because nothing in the system produces
 * one and inventing one on an order page is a promise the store cannot keep.
 */
function statusDetail(order: Order): string | null {
  const shipment = trackable(order)
  const via = shipment?.carrier ? ` via ${shipment.carrier}` : ''

  switch (order.status) {
    case 'pending_payment':
      return 'Complete your payment to process this order.'
    case 'paid':
      return 'Payment received. We will start preparing your order shortly.'
    case 'processing':
      return order.fulfillment_type === 'pickup'
        ? 'Your order is being prepared for pickup.'
        : 'Your order is being prepared for shipping.'
    case 'ready_for_pickup':
      return 'Ready to collect at our pickup location.'
    case 'shipped': {
      const on = formatDate(order.shipped_at)

      return on ? `Shipped on ${on}${via}` : `On its way to you${via}`
    }
    case 'completed': {
      const on = formatDate(order.completed_at)

      return on ? `Order completed on ${on}${via}` : 'Order completed.'
    }
    case 'cancelled': {
      const on = formatDate(order.cancelled_at)

      return on ? `Cancelled on ${on}` : 'This order was cancelled.'
    }
    case 'refunded':
      return 'This order was refunded.'
    default:
      return null
  }
}

// --- buy again -------------------------------------------------------------

/**
 * Reordering adds the order's lines back to the cart one at a time, at the
 * quantities that were bought. It cannot promise anything: the cart caps every
 * line at available stock and silently drops what is out of it, so the result
 * is read back from the cart rather than assumed.
 *
 * A line whose variant has since been deleted has no variant_id at all and is
 * not offered — an order made entirely of them loses the button.
 */
const reorderable = (order: Order) => (order.items ?? []).some((i) => i.variant_id)

const reordering = ref<string | null>(null)
const reorderNotice = ref<{ order: string; message: string } | null>(null)

async function buyAgain(order: Order) {
  const lines = (order.items ?? []).filter((i): i is OrderItemLine & { variant_id: number } =>
    i.variant_id !== null)

  if (!lines.length) return

  reordering.value = order.order_number
  reorderNotice.value = null

  try {
    for (const line of lines) {
      await cart.add(line.variant_id, line.qty)
    }

    const inCart = new Set(cart.items.map((i) => i.variant_id))
    const missing = lines.filter((l) => !inCart.has(l.variant_id))

    if (missing.length === lines.length) {
      reorderNotice.value = {
        order: order.order_number,
        message: 'Nothing from this order is in stock at the moment.',
      }

      return
    }

    if (missing.length) {
      reorderNotice.value = {
        order: order.order_number,
        message: `${missing.length} of ${lines.length} styles are out of stock. The rest are in your cart.`,
      }

      return
    }

    await navigateTo('/cart')
  } finally {
    reordering.value = null
  }
}

useSeoMeta({ title: 'Your orders', robots: 'noindex' })
</script>

<template>
  <AccountShell
    title="My Orders"
    subtitle="Track orders, view past purchases and easily reorder your favourites."
  >
    <!-- Shop again -->
    <div
      class="flex flex-wrap items-center justify-between gap-4 rounded-md border border-edge-subtle
             bg-surface-warm p-5"
    >
      <div class="flex items-center gap-3.5">
        <span class="grid size-11 shrink-0 place-items-center rounded-full bg-white text-ink-700">
          <Package :size="20" aria-hidden="true" />
        </span>
        <div>
          <p class="text-[15px] font-medium text-ink-900">Need to place a new order?</p>
          <p class="mt-0.5 text-[13px] text-ink-700">
            Take advantage of wholesale pricing and keep your team equipped.
          </p>
        </div>
      </div>

      <UiBaseButton to="/products" class="shrink-0">
        Shop Scrubs
        <ArrowRight :size="16" aria-hidden="true" />
      </UiBaseButton>
    </div>

    <!-- Empty -->
    <div
      v-if="!orders.length"
      class="mt-8 rounded-md border border-edge-subtle bg-surface-sunken px-6 py-14 text-center"
    >
      <span class="mx-auto grid size-14 place-items-center rounded-full bg-white text-ink-400">
        <Package :size="22" aria-hidden="true" />
      </span>
      <p class="mt-4 text-[16px] font-medium text-ink-900">No orders yet</p>
      <p class="mx-auto mt-1 max-w-[52ch] text-[14px] text-ink-500">
        Wholesale pricing applies automatically once an order qualifies — no application,
        no approval.
      </p>
      <UiBaseButton to="/products" variant="secondary" class="mt-6">
        Browse the catalogue
      </UiBaseButton>
    </div>

    <template v-else>
      <!-- Filters. Buttons rather than tabs: there is one list underneath, and
           it is filtered in place rather than swapped for another panel. -->
      <div class="mt-8 border-b border-edge-subtle">
        <div class="-mb-px flex gap-6 overflow-x-auto">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            class="shrink-0 border-b-2 pb-3 text-[14px] transition-colors"
            :class="activeTab === tab.key
              ? 'border-ink-900 font-medium text-ink-900'
              : 'border-transparent text-ink-500 hover:text-ink-900'"
            :aria-pressed="activeTab === tab.key"
            @click="activeTab = tab.key"
          >
            {{ tab.label }}
          </button>
        </div>
      </div>

      <ul class="mt-5 space-y-3">
        <li
          v-for="order in visibleOrders"
          :key="order.order_number"
          class="rounded-md border border-edge-subtle p-4 transition-colors hover:border-edge sm:p-5"
        >
          <div class="grid gap-5 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)_auto] lg:gap-8">
            <!-- 1. What it is -->
            <div class="min-w-0">
              <NuxtLink
                :to="`/orders/${order.order_number}`"
                class="text-[15px] font-medium text-ink-900 underline-offset-4 hover:underline"
              >
                Order #{{ order.order_number }}
              </NuxtLink>
              <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[13px] text-ink-500">
                <span v-if="formatDate(order.placed_at)">{{ formatDate(order.placed_at) }}</span>
                <span aria-hidden="true">|</span>
                <span>{{ itemCount(order) }} {{ itemCount(order) === 1 ? 'item' : 'items' }}</span>
                <span aria-hidden="true">|</span>
                <span class="tabular font-medium text-ink-900">
                  {{ order.totals.grand_total.currency }} {{ order.totals.grand_total.formatted }}
                </span>
                <span v-if="order.pricing_tier_name" class="text-sage">
                  · {{ order.pricing_tier_name }}
                </span>
              </p>

              <div v-if="order.items?.length" class="mt-3 flex gap-2">
                <NuxtLink
                  v-for="item in thumbs(order)"
                  :key="item.variant_sku"
                  :to="item.product_slug ? `/products/${item.product_slug}` : `/orders/${order.order_number}`"
                  class="size-16 shrink-0 overflow-hidden rounded-sm border border-edge-subtle bg-surface-sunken"
                  :title="item.product_name"
                >
                  <img
                    v-if="item.image"
                    :src="item.image"
                    :alt="item.product_name"
                    loading="lazy"
                    class="size-full object-cover"
                  >
                </NuxtLink>

                <span
                  v-if="moreLines(order)"
                  class="tabular grid size-16 shrink-0 place-items-center rounded-sm bg-surface-warm
                         text-[13px] text-ink-500"
                >
                  +{{ moreLines(order) }}
                </span>
              </div>
            </div>

            <!-- 2. Where it is -->
            <div class="min-w-0">
              <span
                class="inline-flex items-center gap-1.5 rounded-sm px-2.5 py-1 text-[13px] font-medium"
                :class="styleFor(order.status).chip"
              >
                <component :is="styleFor(order.status).icon" :size="14" aria-hidden="true" />
                {{ order.status_label }}
              </span>

              <p v-if="statusDetail(order)" class="mt-2 text-[13px] leading-snug text-ink-700">
                {{ statusDetail(order) }}
              </p>

              <p v-if="trackable(order)" class="mt-1 text-[13px] text-ink-700">
                Tracking #:
                <a
                  v-if="trackable(order)!.tracking_url"
                  :href="trackable(order)!.tracking_url!"
                  target="_blank"
                  rel="noopener"
                  class="inline-flex items-center gap-1 text-ink-900 underline underline-offset-4"
                >
                  {{ trackable(order)!.tracking_number }}
                  <ExternalLink :size="12" aria-hidden="true" />
                  <span class="sr-only">(opens in a new tab)</span>
                </a>
                <span v-else class="text-ink-900">{{ trackable(order)!.tracking_number }}</span>
              </p>
            </div>

            <!-- 3. What to do about it -->
            <div class="flex flex-col gap-2 lg:w-[190px]">
              <UiBaseButton :to="`/orders/${order.order_number}`" size="sm" block>
                View Order Details
              </UiBaseButton>

              <UiBaseButton
                v-if="reorderable(order)"
                variant="secondary"
                size="sm"
                block
                :loading="reordering === order.order_number"
                :disabled="reordering !== null"
                @click="buyAgain(order)"
              >
                <ShoppingCart
                  v-if="reordering !== order.order_number"
                  :size="15"
                  aria-hidden="true"
                />
                Buy Again
              </UiBaseButton>
            </div>
          </div>

          <p
            v-if="reorderNotice?.order === order.order_number"
            class="mt-3 rounded-sm bg-surface-warm px-3.5 py-2.5 text-[13px] text-ink-700"
            role="status"
          >
            {{ reorderNotice.message }}
            <NuxtLink to="/cart" class="font-medium text-ink-900 underline underline-offset-4">
              View cart
            </NuxtLink>
          </p>
        </li>
      </ul>
    </template>

    <!-- Account & delivery -->
    <div class="mt-10 grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
      <section class="rounded-md border border-edge-subtle p-5" aria-labelledby="account-info">
        <div class="flex items-center justify-between gap-4">
          <h2 id="account-info" class="font-display text-[20px] text-ink-900">
            Account &amp; Delivery Information
          </h2>
          <NuxtLink
            to="/account/profile"
            class="flex shrink-0 items-center gap-1.5 text-[13px] font-medium text-ink-900
                   underline-offset-4 hover:underline"
          >
            Edit Details
            <ArrowRight :size="14" aria-hidden="true" />
          </NuxtLink>
        </div>

        <div class="mt-4 grid gap-5 sm:grid-cols-2">
          <div class="flex gap-3">
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-surface-warm text-ink-700">
              <User :size="16" aria-hidden="true" />
            </span>
            <div class="min-w-0 text-[13px] text-ink-700">
              <p class="font-medium text-ink-900">{{ auth.user?.name }}</p>
              <p class="truncate">{{ auth.user?.email }}</p>
              <p v-if="auth.user?.phone">{{ auth.user.phone }}</p>
            </div>
          </div>

          <div class="flex gap-3">
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-surface-warm text-ink-700">
              <MapPin :size="16" aria-hidden="true" />
            </span>
            <div class="min-w-0 text-[13px] text-ink-700">
              <p class="font-medium text-ink-900">Default Delivery Address</p>
              <template v-if="defaultAddress">
                <p v-for="line in defaultAddress.lines" :key="line">{{ line }}</p>
              </template>
              <template v-else>
                <p>No address saved yet.</p>
                <NuxtLink
                  to="/account/addresses"
                  class="font-medium text-ink-900 underline underline-offset-4"
                >
                  Add one
                </NuxtLink>
              </template>
            </div>
          </div>
        </div>
      </section>

      <section
        class="flex gap-3.5 rounded-md border border-edge-subtle bg-surface-sunken p-5"
        aria-labelledby="bulk-order"
      >
        <span class="grid size-11 shrink-0 place-items-center rounded-full bg-white text-ink-700">
          <Users :size="20" aria-hidden="true" />
        </span>
        <div class="min-w-0">
          <h2 id="bulk-order" class="text-[15px] font-medium text-ink-900">
            Need to place a bulk order?
          </h2>
          <p class="mt-0.5 text-[13px] leading-snug text-ink-700">
            Get wholesale pricing, personalized support and easy reordering for your team.
          </p>
          <UiBaseButton to="/wholesale" variant="secondary" size="sm" class="mt-3">
            Learn About Wholesale
            <ArrowRight :size="14" aria-hidden="true" />
          </UiBaseButton>
        </div>
      </section>
    </div>
  </AccountShell>
</template>
