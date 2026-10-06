<script setup lang="ts">
import { Check } from 'lucide-vue-next'
import type { WholesaleTier } from '~/composables/useApi'

const props = withDefaults(defineProps<{
  tiers: WholesaleTier[]
  /** The rung the current basket has already reached, if any. */
  activeTierId?: number | null
  /** The quantity currently set on the page, which is what picks a rung. */
  qty?: number
  /** Stock for the chosen variant; null while no variant is pinned down. */
  maxQty?: number | null
  unitLabel?: string
}>(), {
  activeTierId: null,
  qty: 1,
  maxQty: null,
  unitLabel: 'item',
})

const emit = defineEmits<{ select: [qty: number] }>()

/**
 * Two different things are true of a rung and they are drawn differently.
 *
 * Unlocked is about the live basket: it is earned by what is already in there
 * and the shopper cannot click it on. Selected is about this page's quantity
 * box: the rung the current quantity lands on. Selection is derived from the
 * quantity rather than stored beside it, so stepping the quantity by hand and
 * clicking a card can never disagree about which rung is lit.
 */
const activeIndex = computed(() =>
  props.tiers.findIndex((tier) => tier.id === props.activeTierId),
)

function isUnlocked(index: number) {
  return activeIndex.value >= 0 && index <= activeIndex.value
}

/** A tier priced only by order value has no quantity to jump the box to. */
function stepQty(tier: WholesaleTier) {
  return tier.min_qty ?? null
}

/** The card the quantity box is currently sitting on: the deepest rung reached. */
const selectedIndex = computed(() => {
  let found = -1
  props.tiers.forEach((tier, index) => {
    const needed = stepQty(tier)
    if (needed !== null && props.qty >= needed) found = index
  })
  return found
})

/** Stock can put a rung out of reach for the chosen variant (§2: say so in words). */
function isOutOfReach(tier: WholesaleTier) {
  const needed = stepQty(tier)
  return needed !== null && props.maxQty !== null && needed > props.maxQty
}

function isSelectable(tier: WholesaleTier) {
  return stepQty(tier) !== null && !isOutOfReach(tier)
}

function select(tier: WholesaleTier) {
  const needed = stepQty(tier)
  if (needed !== null && isSelectable(tier)) emit('select', needed)
}

function threshold(tier: WholesaleTier) {
  const conditions: string[] = []
  if (tier.min_subtotal) conditions.push(`${tier.min_subtotal.formatted} order`)
  if (tier.min_qty) conditions.push(`${tier.min_qty}+ items`)
  return conditions.join(' or ')
}

function cardLabel(tier: WholesaleTier) {
  const needed = stepQty(tier)
  if (needed === null) return undefined
  if (isOutOfReach(tier)) {
    return `${tier.name}, needs ${needed} ${props.unitLabel}s, only ${props.maxQty} in stock`
  }
  return `${tier.name}, set quantity to ${needed} at ${tier.unit_price.formatted} per ${props.unitLabel}`
}
</script>

<template>
  <ul v-if="tiers.length" class="mt-3 space-y-2.5" aria-label="Wholesale price tiers">
    <li v-for="(tier, index) in tiers" :key="tier.id">
      <component
        :is="stepQty(tier) === null ? 'div' : 'button'"
        :type="stepQty(tier) === null ? undefined : 'button'"
        :disabled="stepQty(tier) === null ? undefined : !isSelectable(tier)"
        :aria-pressed="stepQty(tier) === null ? undefined : index === selectedIndex"
        :aria-label="cardLabel(tier)"
        class="wholesale-tier"
        :class="{ 'wholesale-tier--selected': index === selectedIndex }"
        @click="select(tier)"
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
            v-else-if="isOutOfReach(tier)"
            class="text-[11px] leading-none text-ink-400 sm:text-[12px]"
          >
            Only {{ maxQty }} left
          </span>
          <span
            v-else-if="index === 1"
            class="inline-flex rounded-full bg-[#dcece5] px-3 py-1.5 text-[11px] font-medium leading-none text-[#315e4f] sm:text-[12px]"
          >
            Great for resellers
          </span>
        </div>
      </component>
    </li>
  </ul>
</template>

<style scoped>
.wholesale-tier {
  display: grid;
  width: 100%;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.75rem;
  align-items: center;
  min-height: 76px;
  padding: 0.9rem 1rem;
  border: 1px solid var(--color-edge);
  border-radius: var(--radius-sm);
  background: #fff;
  font: inherit;
  text-align: left;
  transition: border-color 160ms ease, background-color 160ms ease;
}

button.wholesale-tier {
  appearance: none;
  cursor: pointer;
}

button.wholesale-tier:hover:not(:disabled) {
  border-color: var(--color-edge-strong);
}

button.wholesale-tier:focus-visible {
  outline: 2px solid var(--color-edge-strong);
  outline-offset: 2px;
}

button.wholesale-tier:disabled {
  cursor: not-allowed;
  background: var(--color-surface-sunken);
}

.wholesale-tier--selected {
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
