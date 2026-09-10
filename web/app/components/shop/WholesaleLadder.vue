<script setup lang="ts">
import type { WholesaleTier } from '~/composables/useApi'

/**
 * The tier ladder for one product, shown to a signed-in shopper on the product
 * page (§3).
 *
 * The lock is only half the mechanic. Once it opens, "wholesale" has to resolve
 * into real numbers on a real product, before anything is in the cart —
 * otherwise unlocking an account rewards the shopper with the absence of a
 * padlock and nothing else.
 *
 * Every figure here came from PricingService. This component does no
 * arithmetic on money.
 */
defineProps<{
  tiers: WholesaleTier[]
  /** The rung the current basket has already reached, if any. */
  activeTierId?: number | null
}>()
</script>

<template>
  <div v-if="tiers.length" class="mt-5 border-y border-edge-subtle py-4">
    <p class="text-[12px] font-medium tracking-[0.04em] text-ink-900 uppercase">
      Wholesale pricing
    </p>

    <!-- A list, not a <table>: three rows of two facts each read better to a
         screen reader as a definition-style list than as a data grid. -->
    <ul class="mt-3 space-y-2">
      <li
        v-for="tier in tiers"
        :key="tier.id"
        class="flex items-baseline justify-between gap-4 text-[13px]"
        :class="tier.id === activeTierId ? 'text-ink-900' : 'text-ink-500'"
      >
        <span class="flex items-baseline gap-2">
          <span :class="tier.id === activeTierId && 'font-medium'">{{ tier.name }}</span>
          <span class="text-ink-400">
            <template v-if="tier.min_subtotal && tier.min_qty">
              {{ tier.min_subtotal.formatted }} or {{ tier.min_qty }}+ items
            </template>
            <template v-else-if="tier.min_subtotal">{{ tier.min_subtotal.formatted }}+</template>
            <template v-else-if="tier.min_qty">{{ tier.min_qty }}+ items</template>
          </span>
          <!-- Colour never carries meaning alone (WCAG 1.4.1). -->
          <span v-if="tier.id === activeTierId" class="text-sage">· applied</span>
        </span>

        <span class="flex shrink-0 items-baseline gap-2">
          <span class="tabular font-medium" :class="tier.id === activeTierId && 'text-sage'">
            {{ tier.unit_price.formatted }}
          </span>
          <span class="tabular text-ink-400">save {{ tier.saving.formatted }}</span>
        </span>
      </li>
    </ul>

    <p class="mt-3 max-w-[52ch] text-[12px] text-ink-400">
      Applied automatically at checkout once your order reaches a threshold —
      across your whole basket, not this item alone.
    </p>
  </div>
</template>
