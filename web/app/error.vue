<script setup lang="ts">
import type { NuxtError } from '#app'

/**
 * The page behind every URL that goes nowhere, and every render that fails.
 *
 * Nuxt's own is a grey screen with a status code on it, which reads as the
 * whole shop being broken when one link was mistyped.
 *
 * DELIBERATELY SELF-CONTAINED. It does not use the site layout: that layout
 * resolves the session and the cart from the API before it draws anything, and
 * one of the ways to arrive here is the API being down. An error page that
 * needs the thing that just failed is how a visitor ends up looking at Nuxt's
 * grey screen after all. So: no layout, no stores, no requests — a wordmark,
 * what happened, and two ways out.
 */
const props = defineProps<{ error: NuxtError }>()

const notFound = computed(() => props.error?.statusCode === 404)

/**
 * The one 404 worth explaining. An order page opens for its owner or for the
 * email address it was placed with, so a perfectly good order number is "not
 * found" to anyone else — including its owner on a device they are not signed
 * in on, following a link that has lost its address.
 */
const orderNotFound = computed(() => notFound.value && props.error?.statusMessage === 'Order not found')

const heading = computed(() => {
  if (orderNotFound.value) return 'We can’t open that order'
  if (notFound.value) return 'We can’t find that page'

  return 'Something went wrong on our side'
})

// app.vue is not rendered for an error, so its title template does not apply.
useSeoMeta({
  title: () => `${notFound.value ? 'Page not found' : 'Something went wrong'} · BulkScrubsDirect`,
  robots: 'noindex',
})

// clearError, not a plain link: the error state has to be dropped as part of
// leaving, or the page that is navigated to renders inside it.
const leave = (to: string) => clearError({ redirect: to })
</script>

<template>
  <div class="flex min-h-screen flex-col bg-white">
    <header class="border-b border-edge-subtle">
      <div class="container-content flex h-16 items-center">
        <a
          href="/"
          class="font-body text-[19px] font-bold tracking-tight text-ink-900"
          @click.prevent="leave('/')"
        >
          BulkScrubs Direct
        </a>
      </div>
    </header>

    <main class="container-content flex flex-1 items-center py-16">
      <div class="max-w-[560px]">
        <p class="text-[12px] font-semibold tracking-[0.18em] text-ink-500 uppercase">
          {{ notFound ? 'Error 404' : `Error ${error?.statusCode ?? 500}` }}
        </p>

        <h1 class="mt-3 font-display text-[36px] leading-tight text-ink-900 sm:text-[44px]">
          {{ heading }}
        </h1>

        <p v-if="orderNotFound" class="mt-4 text-[15px] leading-relaxed text-ink-700">
          An order opens for the account that placed it, or through the link in its confirmation
          email. Sign in to see your orders — or check that the link was copied in full.
        </p>
        <p v-else-if="notFound" class="mt-4 text-[15px] leading-relaxed text-ink-700">
          The link may be out of date, or the address may have been mistyped. Everything we sell is
          a click away.
        </p>
        <p v-else class="mt-4 text-[15px] leading-relaxed text-ink-700">
          The page could not be loaded just now. Nothing you entered has been lost on our end —
          please try again in a moment.
        </p>

        <div class="mt-8 flex flex-wrap gap-3">
          <UiBaseButton v-if="orderNotFound" size="lg" @click="leave('/account/orders')">
            Your orders
          </UiBaseButton>
          <UiBaseButton v-else size="lg" @click="leave('/products')">
            Shop all scrubs
          </UiBaseButton>
          <UiBaseButton variant="secondary" size="lg" @click="leave('/')">
            Back to home
          </UiBaseButton>
        </div>
      </div>
    </main>

    <footer class="border-t border-edge-subtle">
      <div class="container-content py-6 text-[12px] text-ink-500">
        BulkScrubs Direct · Proudly Canadian
      </div>
    </footer>
  </div>
</template>
