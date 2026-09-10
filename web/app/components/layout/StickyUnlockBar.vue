<script setup lang="ts">
import { X } from 'lucide-vue-next'

/**
 * The reference's most transferable pattern: a conversion bar pinned to the
 * bottom of the viewport, always visible without blocking content.
 *
 * Reframed from the reference's first-order discount to the thing that actually
 * matters for this business — unlocking wholesale pricing. Hidden once
 * authenticated, and dismissible.
 */
const auth = useAuthStore()
const { show } = useUnlockModal()
const dismissed = useCookie<boolean>('bsd_unlock_dismissed', {
  maxAge: 60 * 60 * 24 * 7,
  sameSite: 'lax',
})

const visible = computed(() => !auth.isAuthenticated && !dismissed.value)
</script>

<template>
  <div
    v-if="visible"
    class="fixed inset-x-0 bottom-0 z-30 border-t border-edge-subtle bg-white pb-[env(safe-area-inset-bottom)] shadow-[0_-4px_16px_rgb(26_26_26/0.06)]"
  >
    <!-- One row on a phone, or it eats an eighth of the screen and outgrows the
         clearance the layout reserves for it, covering the footer. The full
         sentence and the secondary action are the first things to go: the
         header already carries a sign-in link. -->
    <div class="container-content py-3 sm:py-4">
      <!-- The row that reserves space for the dismiss button is deliberately a
           child of .container-content rather than the same element: that class
           sets `padding-inline`, which outranks a `pr-*` utility on the same
           node, so the reserved gutter silently collapsed and the X sat on top
           of the CTA. -->
      <!-- nowrap below sm: on a 320px screen the CTA would otherwise wrap under
           the text and take the bar to 95px, past the clearance the layout
           reserves. Nowrap makes the copy shrink and re-wrap instead, which
           costs at most one extra line. -->
      <div class="relative flex flex-nowrap items-center justify-center gap-x-3 gap-y-3 pr-11 sm:flex-wrap sm:gap-x-6">
        <!-- No figure here. §3 hides wholesale prices from anyone not signed in,
             and this bar is only ever shown to a guest — a price hardcoded into
             copy leaks just as effectively as one served by the API, and drifts
             from the tier table the moment an admin edits it. -->
        <p class="min-w-0 text-[14px] font-medium text-ink-900 sm:text-center sm:text-[15px]">
          <span class="sm:hidden">Unlock wholesale pricing</span>
          <span class="hidden sm:inline">Sign up to unlock wholesale pricing</span>
          <!-- lg, not sm: at tablet width this clause wraps the bar onto a
               second row, which puts it back over the footer the clearance
               protects. -->
          <span class="hidden font-normal text-ink-500 lg:inline">— applied automatically once your order qualifies</span>
        </p>
        <div class="ml-auto flex shrink-0 items-center gap-2 sm:ml-0">
          <UiBaseButton size="sm" @click="show()">
            <span class="sm:hidden">Sign up</span>
            <span class="hidden sm:inline">Create an account</span>
          </UiBaseButton>
          <UiBaseButton to="/account/login" variant="tertiary" size="sm" class="hidden sm:inline-flex">
            Sign in
          </UiBaseButton>
        </div>
        <button
          class="absolute top-1/2 right-0 grid size-11 -translate-y-1/2 place-items-center text-ink-400 hover:text-ink-900"
          aria-label="Dismiss"
          @click="dismissed = true"
        >
          <X :size="16" />
        </button>
      </div>
    </div>
  </div>
</template>
