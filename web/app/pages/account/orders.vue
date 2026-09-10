<script setup lang="ts">
import type { Order } from '~/composables/useApi'

/** The customer's order history (§8). */
definePageMeta({ middleware: 'auth' })

const api = useApi()

const { data } = await useAsyncData('account-orders', () =>
  api.get<{ data: Order[] }>('/orders'),
)

const orders = computed(() => data.value?.data ?? [])

/**
 * Sage is reserved for wholesale on this site, so it is not spent on status
 * chips. Status is carried by the word itself plus weight — never by colour
 * alone, which WCAG 1.4.1 would fail anyway.
 */
function toneFor(status: string) {
  if (status === 'cancelled' || status === 'refunded') return 'text-ink-400'
  if (status === 'pending_payment') return 'text-ink-500'

  return 'text-ink-900'
}

useSeoMeta({ title: 'Your orders', robots: 'noindex' })
</script>

<template>
  <div class="container-content pt-10 pb-20">
    <h1 class="font-display text-[32px] text-ink-900">Your orders</h1>

    <AccountNav class="mt-6" />

    <p v-if="!orders.length" class="mt-6 max-w-[52ch] text-[15px] text-ink-500">
      You have not placed an order yet. Wholesale pricing applies automatically
      once an order qualifies — no application, no approval.
    </p>

    <ul v-else class="mt-8 divide-y divide-edge-subtle border-y border-edge-subtle">
      <li v-for="order in orders" :key="order.order_number" class="py-5">
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
          <div>
            <NuxtLink
              :to="`/orders/${order.order_number}`"
              class="text-[15px] font-medium text-ink-900 underline-offset-4 hover:underline"
            >
              {{ order.order_number }}
            </NuxtLink>
            <p class="mt-0.5 text-[13px] text-ink-500">
              <span :class="toneFor(order.status)">{{ order.status_label }}</span>
              <span v-if="order.placed_at"> · {{ new Date(order.placed_at).toLocaleDateString('en-CA') }}</span>
              <span v-if="order.pricing_tier_name" class="text-sage"> · {{ order.pricing_tier_name }}</span>
            </p>
          </div>

          <div class="text-right">
            <p class="tabular text-[15px] text-ink-900">{{ order.totals.grand_total.formatted }}</p>
            <p v-if="order.items?.length" class="text-[13px] text-ink-500">
              {{ order.items.reduce((n, i) => n + i.qty, 0) }} items
            </p>
          </div>
        </div>
      </li>
    </ul>

    <UiBaseButton v-if="!orders.length" to="/" variant="secondary" size="sm" class="mt-6">
      Browse the catalogue
    </UiBaseButton>
  </div>
</template>
