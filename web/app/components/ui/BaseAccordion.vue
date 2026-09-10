<script setup lang="ts">
/**
 * The collapsible (+ / -) panels §2 asks for on the product page.
 * Native <details> keeps it keyboard-accessible for free.
 */
defineProps<{ panels: Array<{ key: string; label: string; body: string }> }>()
</script>

<template>
  <div class="divide-y divide-edge-subtle border-y border-edge-subtle">
    <details v-for="(panel, i) in panels" :key="panel.key" :open="i === 0" class="group">
      <summary
        class="flex cursor-pointer list-none items-center justify-between py-4
               text-[15px] font-medium text-ink-900 marker:hidden
               [&::-webkit-details-marker]:hidden"
      >
        {{ panel.label }}
        <span class="relative ml-4 size-4 shrink-0" aria-hidden="true">
          <span class="absolute top-1/2 left-0 h-px w-4 -translate-y-1/2 bg-ink-500" />
          <span
            class="absolute top-0 left-1/2 h-4 w-px -translate-x-1/2 bg-ink-500
                   transition-transform duration-200 group-open:rotate-90 group-open:opacity-0"
          />
        </span>
      </summary>
      <div class="pb-5 text-[14px] leading-relaxed text-ink-700">{{ panel.body }}</div>
    </details>
  </div>
</template>
