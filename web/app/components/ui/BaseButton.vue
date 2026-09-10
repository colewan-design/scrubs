<script setup lang="ts">
import { normalizeClass } from 'vue'

/**
 * §1: black is for text, icons, borders and *small* buttons — never large dark
 * fill areas. Buttons stay compact and never become full-bleed dark bands.
 */
const props = withDefaults(defineProps<{
  variant?: 'primary' | 'secondary' | 'tertiary' | 'wholesale'
  size?: 'sm' | 'md' | 'lg'
  block?: boolean
  disabled?: boolean
  loading?: boolean
  to?: string
  type?: 'button' | 'submit'
}>(), {
  variant: 'primary',
  size: 'md',
  type: 'button',
})

// Must be resolved during setup, not inside the template expression — resolving
// it lazily in the render function fails during SSR.
const NuxtLink = resolveComponent('NuxtLink')
const tag = computed(() => (props.to ? NuxtLink : 'button'))

// A fallthrough `class` is concatenated with ours, never merged, so a call site
// asking for `hidden lg:inline-flex` lands in the same class list as our own
// `inline-flex` — both are display utilities in the same layer, and source
// order (not the call site) decides the winner. It picked `inline-flex`, which
// is how the header's desktop-only CTA stayed on screen at 390px and pushed the
// whole document 47px wide. Stand down when the caller states a display.
const attrs = useAttrs()
const callerSetsDisplay = computed(() =>
  /(?:^|\s)(?:[a-z-]+:)*(?:hidden|block|inline|inline-block|flex|inline-flex|grid|inline-grid|contents)(?=\s|$)/
    .test(normalizeClass(attrs.class)),
)

const variants: Record<string, string> = {
  primary: 'bg-ink-900 text-white hover:bg-ink-700',
  secondary: 'bg-white text-ink-900 border border-edge-strong hover:bg-surface-warm',
  tertiary: 'bg-transparent text-ink-700 hover:text-ink-900 hover:underline underline-offset-4',
  wholesale: 'bg-sage text-white hover:opacity-90',
}

// Every touch target is at least 44px on the two larger sizes (WCAG 2.5.5).
const sizes: Record<string, string> = {
  sm: 'h-9 px-3.5 text-[13px]',
  md: 'h-11 px-5 text-[13px]',
  lg: 'h-12 px-7 text-sm',
}
</script>

<template>
  <component
    :is="tag"
    :to="to"
    :type="to ? undefined : type"
    :disabled="to ? undefined : disabled || loading"
    :aria-busy="loading || undefined"
    class="items-center justify-center gap-2 rounded-sm font-medium tracking-[0.02em] transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-60"
    :class="[!callerSetsDisplay && 'inline-flex', variants[variant], sizes[size], block && 'w-full']"
  >
    <span
      v-if="loading"
      class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent"
      aria-hidden="true"
    />
    <slot />
  </component>
</template>
