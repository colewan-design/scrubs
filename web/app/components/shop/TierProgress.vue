<script setup lang="ts">
import { ArrowRight } from 'lucide-vue-next'
import type { CartQuote } from '~/composables/useApi'

/**
 * Turns the tier table from a pricing rule into an active upsell — the
 * strongest merchandising moment in the build.
 *
 * Three states, in priority order:
 *   1. Guest whose cart already qualifies  -> the highest-intent moment there is
 *   2. Member below the next tier          -> progress meter and what it is worth
 *   3. Member at a tier                    -> confirmation chip
 */
const props = defineProps<{ quote: CartQuote | null }>()

const prompt = computed(() => props.quote?.unlock_prompt ?? null)
const next = computed(() => props.quote?.next_tier ?? null)
const tier = computed(() => props.quote?.tier ?? null)

/** Progress toward the next tier, measured on the retail subtotal. */
const progress = computed(() => {
  const q = props.quote
  if (!q || !next.value?.subtotal_gap_cents) return 0
  const target = q.retail_subtotal_cents + next.value.subtotal_gap_cents
  return target > 0 ? Math.min(100, Math.round((q.retail_subtotal_cents / target) * 100)) : 0
})
</script>

<template>
  <!-- 1. Guest, already qualifying. -->
  <div
    v-if="prompt?.qualifies"
    class="rounded-sm border border-sage/30 bg-sage-soft p-4"
  >
    <p class="text-[15px] font-medium text-ink-900">
      Your order qualifies for wholesale pricing
    </p>
    <p class="mt-1 text-sm text-ink-700">
      Sign in to save
      <span class="font-semibold text-sage tabular">{{ prompt.saving?.formatted }}</span>
      on this order.
    </p>
    <div class="mt-3 flex flex-wrap gap-2">
      <UiBaseButton to="/account/register" variant="wholesale" size="sm">
        Create an account
      </UiBaseButton>
      <UiBaseButton to="/account/login" variant="secondary" size="sm">
        Sign in
      </UiBaseButton>
    </div>
  </div>

  <!-- 2. Member, short of the next tier. -->
  <div
    v-else-if="next && next.subtotal_gap"
    class="rounded-sm border border-edge-subtle bg-surface-warm p-4"
  >
    <p class="text-sm text-ink-700">
      Add <span class="font-semibold text-ink-900 tabular">{{ next.subtotal_gap.formatted }}</span>
      to reach <span class="font-medium text-ink-900">{{ next.name }}</span>
      <template v-if="next.additional_saving && next.additional_saving.cents > 0">
        and save a further
        <span class="font-semibold text-sage tabular">{{ next.additional_saving.formatted }}</span>
      </template>
    </p>
    <div
      class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-surface-warm-deep"
      role="progressbar"
      :aria-valuenow="progress"
      aria-valuemin="0"
      aria-valuemax="100"
      :aria-label="`Progress toward ${next.name}`"
    >
      <div class="h-full rounded-full bg-sage transition-all duration-300" :style="{ width: `${progress}%` }" />
    </div>
    <p v-if="tier" class="mt-2 flex items-center gap-1.5 text-[13px] text-sage">
      <ArrowRight :size="13" aria-hidden="true" />
      {{ tier.name }} pricing applied
    </p>
  </div>

  <!-- 3. Member at the top tier. -->
  <div
    v-else-if="tier"
    class="rounded-sm border border-sage/30 bg-sage-soft px-4 py-3"
  >
    <p class="text-sm font-medium text-sage">{{ tier.name }} wholesale pricing applied</p>
  </div>
</template>
