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
  <div v-if="quote" class="mt-3 rounded-sm border border-edge bg-white px-4 py-3.5 sm:px-5">
    <template v-if="prompt?.qualifies">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-display text-[18px] leading-tight text-ink-900">Your basket qualifies</h3>
        <span class="rounded-full border border-edge px-2.5 py-1 text-[10px] leading-none text-ink-500">
          Sign in to apply
        </span>
      </div>
      <p class="mt-1 text-[13px] text-ink-700">
        Create an account or sign in to save
        <span class="tabular font-semibold text-sage">{{ prompt.saving?.formatted }}</span>.
      </p>
      <div class="mt-3 flex flex-wrap gap-2">
        <UiBaseButton to="/account/register" variant="wholesale" size="sm">Create an account</UiBaseButton>
        <UiBaseButton to="/account/login" variant="secondary" size="sm">Sign in</UiBaseButton>
      </div>
    </template>

    <template v-else-if="next && next.subtotal_gap">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-display text-[18px] leading-tight text-ink-900">Your basket progress</h3>
        <span class="rounded-full border border-edge px-2.5 py-1 text-[10px] leading-none text-ink-500">
          Live basket
        </span>
      </div>

      <p class="mt-1.5 text-[14px] text-ink-700">
        <span class="tabular font-semibold text-ink-900">{{ currentAmount }}</span>
        <span class="tabular"> / {{ targetAmount }}</span>
        toward {{ next.name }}
      </p>

      <div class="mt-2.5 flex items-center gap-3">
        <div
          class="h-2 flex-1 overflow-hidden rounded-full bg-[#ececeb]"
          role="progressbar"
          :aria-valuenow="progress"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-label="`Progress toward ${next.name}`"
        >
          <div class="h-full rounded-full bg-[#5c907e] transition-all duration-300" :style="{ width: `${progress}%` }" />
        </div>
        <span class="tabular w-9 text-right text-[12px] text-ink-500">{{ progress }}%</span>
      </div>

      <p class="mt-2 text-[13px] leading-snug text-sage">
        <template v-if="tier">
          {{ tier.name }} unlocked! Add {{ next.subtotal_gap.formatted }} more to reach {{ next.name }}.
        </template>
        <template v-else>
          Add {{ next.subtotal_gap.formatted }} more to reach {{ next.name }}.
        </template>
      </p>
    </template>

    <template v-else-if="tier">
      <div class="flex items-center gap-2 text-sage">
        <span class="grid size-6 place-items-center rounded-full bg-[#dcece5]" aria-hidden="true">
          <Check :size="13" :stroke-width="2.2" />
        </span>
        <p class="text-[13px] font-medium">{{ tier.name }} wholesale pricing applied</p>
      </div>
    </template>
  </div>
</template>
