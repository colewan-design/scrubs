<script setup lang="ts">
import { Tag, Truck } from 'lucide-vue-next'
import type { CartQuote } from '~/composables/useApi'

/**
 * The two meters across the top of the cart: how far the basket is from
 * wholesale pricing, and how far it is from free shipping.
 *
 * ShopTierProgress says the same thing in one line on the product page. This is
 * the cart's version — both thresholds, side by side, because the cart is where
 * a shopper decides whether to add one more item.
 *
 * The wholesale meter has four states, in priority order:
 *   1. Guest whose basket already qualifies — the highest-intent moment there is
 *   2. Anyone short of the next tier        — the gap, and what it is worth
 *   3. Member at the top tier               — confirmation
 *   4. No tiers configured                  — nothing at all
 *
 * Targets are labelled from /wholesale rather than derived here: those are
 * admin-editable thresholds, already formatted by the server, and the shared
 * useAsyncData key means the header's announcement bar has usually fetched them
 * before this renders.
 */
const props = defineProps<{ quote: CartQuote | null }>()

const { tiers, minOrder } = await useWholesaleSummary()

const prompt = computed(() => props.quote?.unlock_prompt ?? null)
const next = computed(() => props.quote?.next_tier ?? null)
const tier = computed(() => props.quote?.tier ?? null)
const shipping = computed(() => props.quote?.free_shipping ?? null)
const subtotal = computed(() => props.quote?.totals.retail_subtotal ?? null)

/** The threshold being worked toward, as the server formatted it. */
const wholesaleTarget = computed(
  () => tiers.value.find((t) => t.name === next.value?.name)?.min_subtotal ?? minOrder.value,
)

const wholesalePercent = computed(() => {
  const q = props.quote
  if (!q) return 0

  const gap = next.value?.subtotal_gap_cents
  if (!gap) return 100

  const target = q.retail_subtotal_cents + gap

  return target > 0 ? Math.min(100, Math.round((q.retail_subtotal_cents / target) * 100)) : 0
})

const shippingPercent = computed(() => {
  const s = shipping.value
  const q = props.quote
  if (!s || !q) return 0
  if (s.qualifies) return 100

  return s.threshold_cents > 0
    ? Math.min(100, Math.round((q.retail_subtotal_cents / s.threshold_cents) * 100))
    : 0
})

/** State 4: with no tiers to reach, the shipping meter stands alone full width. */
const hasWholesaleMeter = computed(
  () => Boolean(prompt.value?.qualifies || next.value?.subtotal_gap || tier.value),
)
</script>

<template>
  <div class="grid gap-4" :class="hasWholesaleMeter && 'sm:grid-cols-2'">
    <!-- 1. Guest, already qualifying. -->
    <ShopProgressCard
      v-if="prompt?.qualifies"
      :icon="Tag"
      title="Your order qualifies for wholesale pricing"
      :percent="100"
      done
    >
      Sign in to save
      <span class="tabular font-semibold text-sage">
        {{ prompt.saving?.currency }} {{ prompt.saving?.formatted }}
      </span>
      on this order — wholesale prices apply the moment you do.

      <template #action>
        <div class="mt-3 flex flex-wrap gap-2">
          <UiBaseButton to="/account/register" variant="wholesale" size="sm">
            Create an account
          </UiBaseButton>
          <UiBaseButton to="/account/login" variant="secondary" size="sm">Sign in</UiBaseButton>
        </div>
      </template>
    </ShopProgressCard>

    <!-- 2. Short of the next threshold. -->
    <ShopProgressCard
      v-else-if="next?.subtotal_gap"
      :icon="Tag"
      :title="tier ? `You're on your way to ${next.name}!` : `You're on your way to wholesale pricing!`"
      :percent="wholesalePercent"
      :current="subtotal"
      :target="wholesaleTarget"
    >
      <!-- Spelled out per audience rather than assembled from fragments: a
           shopper with no tier yet is being told what unlocking *is*, while one
           who already has tier pricing only wants the next rung's worth. -->
      <template v-if="tier">
        Add
        <span class="tabular font-semibold text-ink-900">
          {{ next.subtotal_gap.currency }} {{ next.subtotal_gap.formatted }}
        </span>
        more to reach {{ next.name }}<template
          v-if="next.additional_saving && next.additional_saving.cents > 0"
        > and save a further
          <span class="tabular font-semibold text-sage">
            {{ next.additional_saving.currency }} {{ next.additional_saving.formatted }}
          </span></template>.
      </template>
      <template v-else>
        Add
        <span class="tabular font-semibold text-ink-900">
          {{ next.subtotal_gap.currency }} {{ next.subtotal_gap.formatted }}
        </span>
        more to reach the
        <template v-if="wholesaleTarget">
          {{ wholesaleTarget.currency }} {{ wholesaleTarget.formatted }}
        </template>
        minimum and unlock wholesale pricing automatically.
      </template>
    </ShopProgressCard>

    <!-- 3. Member at the top tier. -->
    <ShopProgressCard
      v-else-if="tier"
      :icon="Tag"
      :title="`${tier.name} wholesale pricing applied`"
      :percent="100"
      done
    >
      Every line below is priced at your tier. Nothing further to unlock.
    </ShopProgressCard>

    <!-- Free shipping, always shown. -->
    <ShopProgressCard
      v-if="shipping"
      :icon="Truck"
      :title="shipping.qualifies ? 'Your order ships free!' : `You're on your way to free shipping!`"
      :percent="shippingPercent"
      :current="shipping.qualifies ? null : subtotal"
      :target="shipping.qualifies ? null : shipping.threshold"
      :done="shipping.qualifies"
    >
      <template v-if="shipping.qualifies">
        Free shipping is applied to this order on qualifying items.
      </template>
      <!-- Two spellings rather than one conditional parenthetical: a template
           tag between the text and the full stop leaves the stop floating a
           space away from the word it belongs to. -->
      <template v-else-if="shipping.gap && shipping.threshold">
        Add
        <span class="tabular font-semibold text-ink-900">
          {{ shipping.gap.currency }} {{ shipping.gap.formatted }}
        </span>
        more to get free shipping on qualifying orders ({{ shipping.threshold.currency }}
        {{ shipping.threshold.formatted }}+).
      </template>
      <template v-else-if="shipping.gap">
        Add
        <span class="tabular font-semibold text-ink-900">
          {{ shipping.gap.currency }} {{ shipping.gap.formatted }}
        </span>
        more to get free shipping on this order.
      </template>
    </ShopProgressCard>
  </div>
</template>
