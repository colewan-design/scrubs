<script setup lang="ts">
import { ArrowRight, Lock } from 'lucide-vue-next'
import { useRoute } from 'vue-router'
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
  /**
   * The boxed treatment the product page uses: a lock, the offer spelled out,
   * and a sign-in control. Wins over `compact` when both are set.
   */
  panel?: boolean
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
  panel: false,
  interactive: true,
})

/**
 * The template's branches are separated by comments, which Vue keeps in dev —
 * so the component is a fragment there and a single root in production, and a
 * `class` from the call site is silently dropped in dev only. ProductCard
 * depends on that class (`relative z-10`) to lift the lock above the title's
 * stretched-link overlay, so the lock navigated instead of opening the dialog
 * in dev and behaved correctly in prod. Fall through explicitly instead of
 * relying on root-node shape.
 */
defineOptions({ inheritAttrs: false })

const { show } = useUnlockModal()

// The lock opens the sign-up dialog rather than navigating: a guest who clicks
// it is mid-browse, and a full page change costs them the product that prompted
// the click. A <button>, not a link, because nothing is being navigated to.
const tag = computed(() => (props.interactive ? 'button' : 'span'))

/**
 * The panel's control says "Sign In", so it goes to sign-in — the dialog the
 * other locks open leads with joining, and a button whose label promises one
 * thing and opens the other is the kind of small lie that costs trust on the
 * exact screen where §3 is asking for it. The current path rides along so the
 * shopper lands back on the product rather than on the account page.
 */
const route = useRoute()
const signInTo = computed(() => ({ path: '/account/login', query: { redirect: route.fullPath } }))
</script>

<template>
  <!--
    Product page, logged out: the offer stated in full. There is room here for
    the sentence the card has no space for, so the lock stops being a bare
    affordance and becomes the pitch §3 asks for.
  -->
  <div
    v-if="locked && panel"
    v-bind="$attrs"
    class="flex flex-wrap items-center gap-4 rounded-sm border border-edge-subtle bg-surface-warm px-4 py-3.5"
  >
    <span class="grid size-10 shrink-0 place-items-center rounded-sm bg-white text-sage" aria-hidden="true">
      <Lock :size="17" />
    </span>

    <div class="min-w-[12rem] flex-1">
      <p class="text-[14px] font-medium text-ink-900">Unlock wholesale pricing</p>
      <p class="mt-0.5 text-[12px] leading-snug text-ink-500">
        Sign in to see your exclusive wholesale tiers and volume discounts.
      </p>
    </div>

    <UiBaseButton v-if="interactive" :to="signInTo" size="md" class="shrink-0">
      Sign In
    </UiBaseButton>
  </div>

  <!--
    Logged out, on a card: one quiet strip — a lock and the invitation, which is
    what 04-ui-design-system.md specifies for the card and what the redesign
    draws. The redacted-price treatment below is kept for anywhere that asks for
    neither variant, where there is room for it to read as a withheld figure
    rather than as clutter repeated across a six-up grid.
  -->
  <component
    :is="tag"
    v-else-if="locked && compact"
    v-bind="$attrs"
    :type="interactive ? 'button' : undefined"
    class="flex w-full items-center gap-2 rounded-sm bg-surface-warm px-2.5 py-2 text-left
           text-[12px] text-ink-700 transition-colors"
    :class="interactive && 'hover:bg-surface-warm-deep hover:text-ink-900'"
    @click="interactive ? show({ image }) : undefined"
  >
    <Lock :size="13" class="shrink-0 text-sage" aria-hidden="true" />
    Unlock wholesale pricing
  </component>

  <!-- Logged out: the shape of the price, never the price. -->
  <div v-else-if="locked" v-bind="$attrs" class="space-y-2">
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
    v-bind="$attrs"
    class="flex items-center gap-2 text-sage"
    :class="compact ? 'text-[13px]' : 'text-sm'"
  >
    <span class="tabular font-medium">{{ wholesalePrice.formatted }}</span>
    <span v-if="tierName" class="text-ink-500">· {{ tierName }}</span>
  </div>
</template>
