<script setup lang="ts">
import { Check } from 'lucide-vue-next'
import type { WholesaleTier } from '~/composables/useApi'

const props = withDefaults(defineProps<{
  tiers: WholesaleTier[]
  /** The rung the current basket has already reached, if any. */
  activeTierId?: number | null
  unitLabel?: string
}>(), {
  activeTierId: null,
  unitLabel: 'item',
})

/**
 * A reached tier stays visibly unlocked while the strongest reached tier gets
 * the accent border. This makes progress legible without relying on colour.
 */
const activeIndex = computed(() =>
  props.tiers.findIndex((tier) => tier.id === props.activeTierId),
)

function isUnlocked(index: number) {
  return activeIndex.value >= 0 && index <= activeIndex.value
}

function threshold(tier: WholesaleTier) {
  const conditions: string[] = []
  if (tier.min_subtotal) conditions.push(`${tier.min_subtotal.formatted} order`)
  if (tier.min_qty) conditions.push(`${tier.min_qty}+ items`)
  return conditions.join(' or ')
}
</script>

<template>
  <ul v-if="tiers.length" class="mt-3 space-y-2.5" aria-label="Wholesale price tiers">
    <li
      v-for="(tier, index) in tiers"
      :key="tier.id"
      class="wholesale-tier"
      :class="{
        'wholesale-tier--active': tier.id === activeTierId,
        'wholesale-tier--featured': index === 1,
        'wholesale-tier--unlocked': isUnlocked(index),
      }"
    >
      <div class="min-w-0">
        <p class="tabular text-[20px] font-semibold leading-none text-ink-900 sm:text-[21px]">
          {{ tier.unit_price.formatted }}
          <span class="text-[16px] font-medium">/ {{ unitLabel }}</span>
        </p>
        <p class="mt-1.5 text-[13px] leading-snug text-ink-500 sm:text-[14px]">
          {{ tier.name }}<span v-if="threshold(tier)"> · {{ threshold(tier) }}</span>
        </p>
      </div>

      <div class="wholesale-tier__saving">
        <p class="tabular text-[13px] font-medium text-sage sm:text-[14px]">
          Save {{ tier.saving.formatted }} per {{ unitLabel }}
        </p>
      </div>

      <div class="flex justify-start sm:justify-end">
        <span
          v-if="isUnlocked(index)"
          class="inline-flex items-center gap-1.5 rounded-full bg-[#dcece5] px-3 py-1.5 text-[11px] font-medium leading-none text-[#315e4f] sm:text-[12px]"
        >
          Unlocked
          <Check :size="13" :stroke-width="2" aria-hidden="true" />
        </span>
        <span
          v-else-if="index === 1"
          class="inline-flex rounded-full bg-[#dcece5] px-3 py-1.5 text-[11px] font-medium leading-none text-[#315e4f] sm:text-[12px]"
        >
          Great for resellers
        </span>
      </div>
    </li>
  </ul>
</template>

<style scoped>
.wholesale-tier {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.75rem;
  align-items: center;
  min-height: 76px;
  padding: 0.9rem 1rem;
  border: 1px solid var(--color-edge);
  border-radius: var(--radius-sm);
  background: #fff;
  transition: border-color 160ms ease, background-color 160ms ease;
}

.wholesale-tier--featured {
  border-color: var(--color-sage);
  background: #f4f8f6;
  box-shadow: inset 0 0 0 1px rgb(74 107 93 / 0.08);
}

.wholesale-tier__saving {
  border-top: 1px solid var(--color-edge-subtle);
  padding-top: 0.7rem;
}

@media (min-width: 640px) {
  .wholesale-tier {
    grid-template-columns: minmax(0, 1.35fr) minmax(150px, 0.8fr) auto;
    gap: 1rem;
    padding: 0.9rem 1.1rem;
  }

  .wholesale-tier__saving {
    display: flex;
    min-height: 38px;
    align-items: center;
    border-top: 0;
    border-left: 1px solid var(--color-edge-subtle);
    padding-top: 0;
    padding-left: 1rem;
  }
}
</style>
