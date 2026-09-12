<script setup lang="ts">
const auth = useAuthStore()
const cart = useCartStore()

// Resolve session and cart on the server so the first paint already carries the
// right pricing state — no flash of guest pricing for a signed-in member. The
// auth guard may have resolved the session already on an account route, in
// which case ensureSession() costs nothing.
await useAsyncData('session', async () => {
  await Promise.all([auth.ensureSession(), cart.refresh()])
  return true
})

// Nothing between a shopper and paying us: the sign-up band has no business
// interrupting checkout or an account screen. The cart is the exception the
// redesign makes — it closes with the band, under the recommendation strip,
// where it reads as part of the page rather than an interruption.
const route = useRoute()
const showNewsletter = computed(
  () => !/^\/(checkout|account|orders)(\/|$)/.test(route.path),
)
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <LayoutSiteHeader />

    <main class="flex-1">
      <slot />
    </main>

    <!-- One gap between the page and everything that closes it, wherever the
         band itself is suppressed (members, checkout, cart) — putting the
         margin on the footer instead left a 5rem hole under the band on every
         page that shows one. -->
    <div class="mt-20">
      <LayoutNewsletterBand v-if="showNewsletter" />
      <LayoutSiteFooter />
    </div>

    <LayoutStickyUnlockBar />

    <!-- Mounted once here; every lock on the page opens this same dialog. Never
         for a member: no lock can open it, and mounting it would have them
         fetching the list of sign-in providers on every page for nothing. -->
    <ShopUnlockModal v-if="!auth.isAuthenticated" />

    <!-- Clearance so the sticky bar never covers the footer's last row. Tracks
         the bar's own two heights — see StickyUnlockBar's single-row phone
         layout — plus the home-indicator inset it pads itself with. -->
    <div
      v-if="!auth.isAuthenticated"
      class="h-[calc(4.5rem+env(safe-area-inset-bottom))] sm:h-[calc(5rem+env(safe-area-inset-bottom))]"
      aria-hidden="true"
    />
  </div>
</template>
