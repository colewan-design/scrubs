<script setup lang="ts">
import { ArrowRight } from 'lucide-vue-next'
import type { Money } from '~/composables/useApi'

/**
 * The signature interaction of the whole site.
 *
 * §3 requires wholesale prices hidden from logged-out visitors while still
 * feeling present and attainable. The locked state shows the SHAPE of a price —
 * a currency mark and a redacted bar — so a guest can see that a second, better
 * number exists here, then an explicit control to reveal it.
 *
 * That bar is NOT a blurred price. There is no wholesale figure in the markup
 * to blur: the API never sends one to a guest (see ProductCardResource), so a
 * CSS-blurred real number would be a §3 leak readable in view-source and in the
 * network tab, defeated by one line of devtools. Rendering a placeholder gets
 * the same visual promise with nothing to steal — which is also what the Faire
 * reference does.
 */
const props = withDefaults(defineProps<{
  locked: boolean
  wholesalePrice?: Money | null
  tierName?: string | null
  compact?: boolean
  /** The thumbnail the dialog shows, so it opens on what was being looked at. */
  image?: { path: string; alt: string } | null
  /**
   * Set false where an ancestor is already interactive — nesting a control
   * inside a link is invalid HTML and breaks hydration. There the lock renders
   * as plain text and the ancestor carries the click.
   */
  interactive?: boolean
}>(), {
  compact: false,
  interactive: true,
})

const { show } = useUnlockModal()

// The lock opens the sign-up dialog rather than navigating: a guest who clicks
// it is mid-browse, and a full page change costs them the product that prompted
// the click. A <button>, not a link, because nothing is being navigated to.
const tag = computed(() => (props.interactive ? 'button' : 'span'))
</script>

<template>
  <!-- Logged out: the shape of the price, never the price. -->
  <div v-if="locked" class="space-y-2">
    <p class="flex items-center gap-1.5" :class="compact ? 'text-[15px]' : 'text-[17px]'">
      <span class="font-medium text-ink-900" aria-hidden="true">$</span>
      <!--
        Deliberately static. A shimmer would read as "still loading", and this
        is not loading — it is withheld, and the control below says so.
      -->
      <span
        class="block rounded-[3px] bg-ink-900/10"
        :class="compact ? 'h-3.5 w-14' : 'h-4 w-20'"
        aria-hidden="true"
      />
      <span class="sr-only">Wholesale price hidden until you sign in</span>
    </p>

    <component
      :is="tag"
      :type="interactive ? 'button' : undefined"
      class="inline-flex items-center gap-2 rounded-sm border border-edge-strong text-left
             text-ink-900 transition-colors"
      :class="[
        compact ? 'px-3 py-1.5 text-[13px]' : 'px-3.5 py-2 text-sm',
        interactive && 'hover:border-ink-900 hover:bg-surface-warm',
      ]"
      @click="interactive ? show({ image }) : undefined"
    >
      Unlock wholesale price
      <ArrowRight :size="compact ? 13 : 15" aria-hidden="true" />
    </component>
  </div>

  <!-- Signed in and qualified. Colour never carries the meaning alone
       (WCAG 1.4.1) — the tier name always accompanies it. -->
  <div
    v-else-if="wholesalePrice"
    class="flex items-center gap-2 text-sage"
    :class="compact ? 'text-[13px]' : 'text-sm'"
  >
    <span class="tabular font-medium">{{ wholesalePrice.formatted }}</span>
    <span v-if="tierName" class="text-ink-500">· {{ tierName }}</span>
  </div>
</template>
