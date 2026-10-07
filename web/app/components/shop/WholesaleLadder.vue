<script setup lang="ts">
import { Check, Star, Truck } from 'lucide-vue-next'
import type { Money, WholesaleTier } from '~/composables/useApi'

const props = withDefaults(defineProps<{
  tiers: WholesaleTier[]
  /** Retail, drawn as the first rung — the price with no threshold attached. */
  retailPrice: Money
  /** Order value that earns free shipping, from the admin-editable setting. */
  freeShippingCents?: number | null
  /** The rung the current basket has already reached, if any. */
  activeTierId?: number | null
  /** The quantity currently set on the page, which is what picks a rung. */
  qty?: number
  /** Stock for the chosen variant; null while no variant is pinned down. */
  maxQty?: number | null
  unitLabel?: string
}>(), {
  freeShippingCents: null,
  activeTierId: null,
  qty: 1,
  maxQty: null,
  unitLabel: 'item',
})

const emit = defineEmits<{ select: [qty: number] }>()

interface Rung {
  key: string
  name: string
  price: Money
  /** What earns this rung, in the reference's words. */
  threshold: string
  savingPercent: number | null
  /** Quantity this card jumps the stepper to; null when there is none to jump to. */
  stepQty: number | null
  freeShipping: boolean
  resellerPick: boolean
  tierId: number | null
}

/**
 * Against the tier's own retail, reconstructed as price + saving rather than
 * read off the page. The page's figure is per-variant and can carry a size
 * upcharge, so dividing by it would print a different percentage on the 3XL
 * than on the S for one and the same tier.
 */
function savingPercent(tier: WholesaleTier): number | null {
  const retailCents = tier.unit_price.cents + tier.saving.cents
  if (retailCents <= 0 || tier.saving.cents <= 0) return null
  return Math.round((tier.saving.cents / retailCents) * 100)
}

function thresholdLabel(tier: WholesaleTier): string {
  const conditions: string[] = []
  if (tier.min_subtotal) conditions.push(`${tier.min_subtotal.formatted}+ order`)
  if (tier.min_qty) conditions.push(`${tier.min_qty}+ ${props.unitLabel}s`)
  return conditions.join(' or ')
}

/**
 * Free shipping is a cart-level threshold rather than a field on the tier, but
 * a rung whose own minimum already clears it cannot be reached without earning
 * it. That makes the badge a fact about the rung, not a promise bolted on.
 */
function earnsFreeShipping(tier: WholesaleTier): boolean {
  const threshold = props.freeShippingCents
  return threshold !== null
    && threshold > 0
    && tier.min_subtotal_cents !== null
    && tier.min_subtotal_cents >= threshold
}

const rungs = computed<Rung[]>(() => [
  {
    key: 'retail',
    name: `1 ${props.unitLabel}`,
    price: props.retailPrice,
    threshold: 'Retail purchase',
    // No badge. The reference sheet prints "SAVE 15%" on this card, but retail
    // is the figure every other rung is discounted FROM — there is nothing for
    // it to be cheaper than, and no field behind the number. A saving that is
    // not real is not one to put on a live storefront.
    savingPercent: null,
    stepQty: 1,
    freeShipping: false,
    resellerPick: false,
    tierId: null,
  },
  ...props.tiers.map((tier, index) => ({
    key: `tier-${tier.id}`,
    name: tier.name,
    price: tier.unit_price,
    threshold: thresholdLabel(tier),
    savingPercent: savingPercent(tier),
    stepQty: tier.min_qty ?? null,
    freeShipping: earnsFreeShipping(tier),
    // The second wholesale rung is the one the sheet flags, and the one a
    // reseller actually lands on — the top rung is a distributor's order.
    resellerPick: index === 1,
    tierId: tier.id,
  })),
])

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
  rungs.value.findIndex((rung) => rung.tierId !== null && rung.tierId === props.activeTierId),
)

/** Retail is excluded: "unlocked" is a wholesale claim and rung 0 is not one. */
function isUnlocked(index: number) {
  return index > 0 && activeIndex.value >= 0 && index <= activeIndex.value
}

/** The card the quantity box is currently sitting on: the deepest rung reached. */
const selectedIndex = computed(() => {
  let found = 0
  rungs.value.forEach((rung, index) => {
    if (rung.stepQty !== null && props.qty >= rung.stepQty) found = index
  })
  return found
})

/** Stock can put a rung out of reach for the chosen variant (§2: say so in words). */
function isOutOfReach(rung: Rung) {
  return rung.stepQty !== null && props.maxQty !== null && rung.stepQty > props.maxQty
}

function isSelectable(rung: Rung) {
  return rung.stepQty !== null && !isOutOfReach(rung)
}

function select(rung: Rung) {
  if (rung.stepQty !== null && isSelectable(rung)) emit('select', rung.stepQty)
}

function cardLabel(rung: Rung) {
  if (rung.stepQty === null) return undefined
  if (isOutOfReach(rung)) {
    return `${rung.name}, needs ${rung.stepQty} ${props.unitLabel}s, only ${props.maxQty} in stock`
  }
  return `${rung.name}, set quantity to ${rung.stepQty} at ${rung.price.formatted} per ${props.unitLabel}`
}
</script>

<template>
  <ul v-if="tiers.length" class="rung-grid" aria-label="Price tiers">
    <li v-for="(rung, index) in rungs" :key="rung.key" class="contents">
      <component
        :is="rung.stepQty === null ? 'div' : 'button'"
        :type="rung.stepQty === null ? undefined : 'button'"
        :disabled="rung.stepQty === null ? undefined : !isSelectable(rung)"
        :aria-pressed="rung.stepQty === null ? undefined : index === selectedIndex"
        :aria-label="cardLabel(rung)"
        class="rung"
        :class="{ 'rung--selected': index === selectedIndex }"
        @click="select(rung)"
      >
        <div class="rung__head">
          <span class="rung__name">{{ rung.name }}</span>
          <span v-if="rung.savingPercent" class="rung__save">Save {{ rung.savingPercent }}%</span>
        </div>

        <p class="rung__price tabular">{{ rung.price.formatted }}</p>
        <p class="rung__unit">/ {{ unitLabel }}</p>
        <p v-if="rung.threshold" class="rung__threshold">{{ rung.threshold }}</p>

        <p v-if="rung.freeShipping" class="rung__ship">
          <Truck :size="15" :stroke-width="1.8" aria-hidden="true" />
          Free shipping
        </p>

        <!-- Not on the reference sheet, which was drawn against an empty
             basket. Kept because it is the only thing on the page that says
             which rung the shopper has already earned, and it borrows the
             sheet's green rather than introducing a fourth accent. -->
        <p v-if="isUnlocked(index)" class="rung__ship">
          <Check :size="15" :stroke-width="2" aria-hidden="true" />
          Unlocked
        </p>

        <p v-if="rung.resellerPick" class="rung__note">
          <Star :size="12" fill="currentColor" :stroke-width="0" aria-hidden="true" />
          Best for resellers
        </p>
        <p v-else-if="isOutOfReach(rung)" class="rung__note">Only {{ maxQty }} left</p>
      </component>
    </li>
  </ul>
</template>

<style scoped>
.rung-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.75rem;
}

@media (min-width: 480px) {
  .rung-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

.rung {
  display: flex;
  width: 100%;
  height: 100%;
  flex-direction: column;
  align-items: flex-start;
  gap: 0;
  padding: 0.9rem 1rem 1rem;
  border: 1px solid var(--ladder-edge);
  border-radius: 6px;
  background: #fff;
  font: inherit;
  text-align: left;
  transition: border-color 160ms ease, box-shadow 160ms ease;
}

button.rung {
  appearance: none;
  cursor: pointer;
}

button.rung:hover:not(:disabled) {
  border-color: var(--ladder-navy);
}

button.rung:focus-visible {
  outline: 2px solid var(--ladder-navy);
  outline-offset: 2px;
}

button.rung:disabled {
  cursor: not-allowed;
  background: #f7f8fa;
}

/* The sheet asks for a 1.5px border when selected and 1px when not. Growing
   the border itself would shift every card in the row by half a pixel on
   click, so the extra half is painted inside instead. */
.rung--selected {
  border-color: var(--ladder-navy);
  box-shadow: inset 0 0 0 0.5px var(--ladder-navy);
}

.rung__head {
  display: flex;
  width: 100%;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
}

.rung__name {
  font-size: 15px;
  font-weight: 600;
  color: var(--ladder-ink);
}

.rung__save {
  flex-shrink: 0;
  font-size: 14px;
  font-weight: 600;
  text-transform: uppercase;
  color: var(--ladder-orange);
}

.rung__price {
  margin-top: 0.55rem;
  font-size: 24px;
  font-weight: 600;
  line-height: 1.1;
  color: var(--ladder-ink);
}

.rung__unit {
  margin-top: 0.15rem;
  font-size: 12px;
  font-weight: 400;
  color: var(--ladder-slate);
}

.rung__threshold {
  margin-top: 0.45rem;
  font-size: 13px;
  font-weight: 400;
  line-height: 1.4;
  color: var(--ladder-slate);
}

.rung__ship {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-top: 0.5rem;
  font-size: 14px;
  font-weight: 500;
  color: var(--ladder-green);
}

.rung__note {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-top: 0.45rem;
  font-size: 12px;
  font-weight: 500;
  color: var(--ladder-slate);
}
</style>
