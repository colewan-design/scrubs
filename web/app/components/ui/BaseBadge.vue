<script setup lang="ts">
withDefaults(defineProps<{ tone?: 'neutral' | 'dark' | 'sage' | 'muted' }>(), {
  tone: 'neutral',
})

const tones: Record<string, string> = {
  neutral: 'bg-white text-ink-900 border border-edge-subtle',
  dark: 'bg-ink-900 text-white',
  sage: 'bg-sage-soft text-sage border border-sage/25',
  muted: 'bg-surface-warm text-ink-500 border border-edge-subtle',
}
</script>

<template>
  <span
    class="inline-flex items-center gap-1 rounded-sm px-2 py-1 text-[10px] font-semibold
           uppercase tracking-[0.08em]"
    :class="tones[tone]"
  >
    <slot />
  </span>
</template>
