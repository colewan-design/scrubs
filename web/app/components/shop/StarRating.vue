<script setup lang="ts">
import { Star } from 'lucide-vue-next'

/**
 * Average-rating display.
 *
 * The fractional star is drawn by overlaying a filled row on a hollow one and
 * clipping it to the average — rounding to the nearest whole star would show
 * 4.8 and 4.5 identically, which is the one thing the figure is there to
 * distinguish.
 *
 * Amber is a deliberate exception to the palette: it is the convention readers
 * expect for a rating, and sage is spoken for (§1 reserves it for wholesale).
 */
const props = withDefaults(defineProps<{
  rating: number
  count?: number | null
  size?: number
}>(), { count: null, size: 15 })

const pct = computed(() => `${Math.max(0, Math.min(5, props.rating)) / 5 * 100}%`)
</script>

<template>
  <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
    <!-- One label for the whole widget; the stars themselves are decorative. -->
    <span
      class="relative inline-flex shrink-0"
      role="img"
      :aria-label="`Rated ${rating} out of 5${count ? ` from ${count} reviews` : ''}`"
    >
      <span class="flex gap-0.5 text-edge" aria-hidden="true">
        <Star v-for="n in 5" :key="n" :size="size" fill="currentColor" stroke-width="0" />
      </span>
      <span
        class="absolute inset-0 flex gap-0.5 overflow-hidden text-amber-500"
        :style="{ width: pct }"
        aria-hidden="true"
      >
        <Star v-for="n in 5" :key="n" :size="size" fill="currentColor" stroke-width="0" class="shrink-0" />
      </span>
    </span>

    <span class="text-[13px] text-ink-700" aria-hidden="true">
      {{ rating }}
      <span v-if="count" class="text-ink-500">({{ count }} reviews)</span>
    </span>
  </div>
</template>
