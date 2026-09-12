<script setup lang="ts">
import { Truck, RotateCcw } from 'lucide-vue-next'

/**
 * The three reassurances that sit under the buy box.
 *
 * Hairline dividers rather than cards: §1 keeps this band quiet, and three
 * bordered boxes directly below the primary CTA compete with it.
 *
 * Stacks to one column on a phone, where three 100px columns turn every line
 * into two or three words.
 */
const items = [
  { icon: Truck, title: 'Fast, reliable', body: 'shipping across Canada' },
  { icon: RotateCcw, title: 'Easy returns', body: 'within 30 days' },
]
</script>

<template>
  <ul class="grid gap-4 border-y border-edge-subtle py-5 sm:grid-cols-3 sm:gap-0">
    <li
      v-for="(item, i) in items"
      :key="item.title"
      class="flex items-start gap-2.5 sm:px-4"
      :class="i > 0 && 'sm:border-l sm:border-edge-subtle'"
    >
      <component :is="item.icon" :size="19" class="mt-0.5 shrink-0 text-ink-500" aria-hidden="true" />
      <p class="text-[12px] leading-snug text-ink-700">
        <span class="block font-medium text-ink-900">{{ item.title }}</span>
        {{ item.body }}
      </p>
    </li>

    <!-- Emoji rather than an icon: lucide has no maple leaf, and a hand-rolled
         path would be the one piece of fabricated brand art on the site.
         Matches SiteFooter. -->
    <li class="flex items-start gap-2.5 sm:border-l sm:border-edge-subtle sm:px-4">
      <span class="mt-0.5 shrink-0 text-[17px] leading-none" aria-hidden="true">🍁</span>
      <p class="text-[12px] leading-snug text-ink-700">
        <span class="block font-medium text-ink-900">Proudly Canadian</span>
        Supporting healthcare across Canada.
      </p>
    </li>
  </ul>
</template>
