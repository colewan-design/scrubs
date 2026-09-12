<script setup lang="ts">
import type { Component } from 'vue'
import type { Money } from '~/composables/useApi'

/**
 * One "you're on your way to X" meter. The cart shows a pair of them — the
 * wholesale threshold and the free-shipping threshold — which is the whole
 * upsell argument stated once, above the lines, rather than buried in the
 * summary column.
 *
 * The card never computes money. `current` and `target` are server-formatted
 * figures; `percent` is a ratio of cents, which is the one thing the frontend
 * is allowed to derive because nothing is ever charged from it.
 */
withDefaults(defineProps<{
  icon: Component
  title: string
  /** 0–100. Caller clamps. */
  percent: number
  current?: Money | null
  target?: Money | null
  /** Threshold already met — the card settles into sage rather than urging. */
  done?: boolean
}>(), { done: false })
</script>

<template>
  <div
    class="rounded-md border p-4"
    :class="done ? 'border-sage/30 bg-sage-soft' : 'border-edge-subtle bg-surface-warm'"
  >
    <div class="flex gap-3.5">
      <span
        class="grid size-10 shrink-0 place-items-center rounded-full bg-white"
        :class="done ? 'text-sage' : 'text-ink-700'"
      >
        <component :is="icon" :size="18" aria-hidden="true" />
      </span>

      <div class="min-w-0 flex-1">
        <p class="text-[14px] font-semibold text-ink-900">{{ title }}</p>
        <p class="mt-0.5 text-[13px] leading-snug text-ink-700"><slot /></p>

        <div
          class="mt-3 h-1.5 overflow-hidden rounded-full bg-white"
          role="progressbar"
          :aria-valuenow="percent"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-label="title"
        >
          <div
            class="h-full rounded-full transition-all duration-300"
            :class="done ? 'bg-sage' : 'bg-ink-900'"
            :style="{ width: `${percent}%` }"
          />
        </div>

        <p v-if="current && target" class="tabular mt-1.5 text-[12px] text-ink-500">
          <span class="font-medium text-ink-900">{{ current.currency }} {{ current.formatted }}</span>
          / {{ target.currency }} {{ target.formatted }}
        </p>

        <slot name="action" />
      </div>
    </div>
  </div>
</template>
