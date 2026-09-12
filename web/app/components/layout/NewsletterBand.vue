<script setup lang="ts">
/**
 * "Join the BulkScrubs Direct community" band, sitting directly above the
 * footer.
 *
 * There is no subscriber list behind this yet — no endpoint, no table, no
 * admin screen — so the form does NOT pretend to store an address. It carries
 * the address straight into the one-minute sign-up, which the register page
 * already accepts as ?email= (the unlock dialog hands it over the same way).
 * That is the only honest destination available today; wiring a real list is a
 * small API addition when the client wants one.
 *
 * Hidden from members: they are already through the door, and the address they
 * would type is the one they signed up with.
 */
const auth = useAuthStore()
const email = ref('')

function submit() {
  const value = email.value.trim()
  if (!value) return
  navigateTo({ path: '/account/register', query: { email: value } })
}
</script>

<template>
  <section
    v-if="!auth.isAuthenticated"
    class="border-y border-edge-subtle bg-surface-warm-deep"
    aria-labelledby="newsletter-heading"
  >
    <div
      class="container-content flex flex-wrap items-center justify-between gap-x-10 gap-y-6 py-10"
    >
      <div class="min-w-[min(100%,22rem)] flex-1">
        <h2 id="newsletter-heading" class="font-display text-[24px] text-ink-900">
          Join the BulkScrubs Direct community
        </h2>
        <p class="mt-1.5 max-w-[62ch] text-[14px] text-ink-700">
          Be the first to know about new arrivals, special offers and wholesale updates.
        </p>
      </div>

      <form class="flex w-full max-w-[440px] flex-col gap-2" @submit.prevent="submit">
        <div class="flex flex-wrap gap-2 sm:flex-nowrap">
          <input
            v-model="email"
            type="email"
            required
            autocomplete="email"
            aria-label="Your email address"
            placeholder="Your email address"
            class="h-11 min-w-0 flex-1 rounded-sm border border-edge bg-white px-4 text-[14px] text-ink-900 placeholder:text-ink-400 focus:border-edge-strong focus:outline-none"
          >
          <UiBaseButton type="submit" size="md" class="shrink-0">Subscribe</UiBaseButton>
        </div>
        <p class="text-[12px] text-ink-500">
          Takes you to a one-minute sign-up — which also unlocks wholesale pricing.
        </p>
      </form>
    </div>
  </section>
</template>
