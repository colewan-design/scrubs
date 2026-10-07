<script setup lang="ts">
import { Check } from 'lucide-vue-next'
import type { CartQuote } from '~/composables/useApi'

const props = defineProps<{ quote: CartQuote | null }>()

const prompt = computed(() => props.quote?.unlock_prompt ?? null)
const next = computed(() => props.quote?.next_tier ?? null)
const tier = computed(() => props.quote?.tier ?? null)

const targetCents = computed(() => {
  const q = props.quote
  if (!q || !next.value?.subtotal_gap_cents) return null
  return q.retail_subtotal_cents + next.value.subtotal_gap_cents
})

const progress = computed(() => {
  const q = props.quote
  if (!q || !targetCents.value) return 0
  return Math.min(100, Math.round((q.retail_subtotal_cents / targetCents.value) * 100))
})

const currentAmount = computed(() => props.quote?.totals.retail_subtotal.formatted ?? '')
const targetAmount = computed(() => {
  const cents = targetCents.value
  if (cents === null) return ''
  const amount = cents / 100
  return `$${Number.isInteger(amount) ? amount.toFixed(0) : amount.toFixed(2)}`
})
</script>

<template>
  <div v-if="quote" class="progress">
    <template v-if="prompt?.qualifies">
      <div class="progress__row">
        <h3 class="progress__title">Your basket qualifies</h3>
      </div>
      <p class="progress__note">
        Create an account or sign in to save
        <span class="tabular progress__amount">{{ prompt.saving?.formatted }}</span>.
      </p>
      <div class="mt-3 flex flex-wrap gap-2">
        <UiBaseButton to="/account/register" variant="wholesale" size="sm">Create an account</UiBaseButton>
        <UiBaseButton to="/account/login" variant="secondary" size="sm">Sign in</UiBaseButton>
      </div>
    </template>

    <template v-else-if="next && next.subtotal_gap">
      <div class="progress__row">
        <h3 class="progress__title">Your basket progress</h3>
        <!-- The sheet pairs the heading with the raw figures rather than a
             percentage; the bar already carries the proportion. -->
        <p class="tabular progress__figures">{{ currentAmount }} / {{ targetAmount }}</p>
      </div>

      <div
        class="progress__track"
        role="progressbar"
        :aria-valuenow="progress"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-label="`Progress toward ${next.name}`"
      >
        <div class="progress__fill" :style="{ width: `${progress}%` }" />
      </div>

      <p class="progress__note">
        <template v-if="tier">
          {{ tier.name }} unlocked. Add {{ next.subtotal_gap.formatted }} more to unlock {{ next.name }}.
        </template>
        <template v-else>
          Add {{ next.subtotal_gap.formatted }} more to unlock {{ next.name }}.
        </template>
      </p>
    </template>

    <template v-else-if="tier">
      <p class="progress__applied">
        <Check :size="15" :stroke-width="2" aria-hidden="true" />
        {{ tier.name }} wholesale pricing applied
      </p>
    </template>
  </div>
</template>

<style scoped>
.progress__row {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
}

/* Explicit: the global h1–h3 rule puts headings in the display serif at
   weight 400, and this block is specified in Inter Semibold. */
.progress__title {
  font-family: inherit;
  font-size: 14px;
  font-weight: 600;
  color: var(--ladder-ink);
}

.progress__figures {
  font-size: 13px;
  font-weight: 400;
  color: var(--ladder-slate);
}

.progress__track {
  overflow: hidden;
  height: 8px;
  margin-top: 0.7rem;
  border-radius: 999px;
  background: var(--ladder-track);
}

.progress__fill {
  height: 100%;
  border-radius: 999px;
  background: var(--ladder-bar);
  transition: width 300ms ease;
}

.progress__note {
  margin-top: 0.55rem;
  font-size: 12px;
  font-weight: 400;
  line-height: 1.5;
  color: var(--ladder-slate);
}

.progress__amount {
  font-weight: 600;
  color: var(--ladder-green);
}

.progress__applied {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 14px;
  font-weight: 500;
  color: var(--ladder-green);
}
</style>
