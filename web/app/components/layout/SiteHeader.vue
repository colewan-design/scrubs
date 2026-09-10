<script setup lang="ts">
import { Search, ShoppingBag, Menu, X } from 'lucide-vue-next'

/**
 * Three stacked rows, exactly as the reference:
 *   1. announcement bar (warm ground)  2. main bar  3. category nav
 * Rows 2-3 stick on scroll; the announcement bar detaches.
 *
 * Below lg the third row collapses into the drawer, which also carries the
 * search field — the main bar has no room for it next to the wordmark at 390px,
 * and dropping search entirely is not an option on a storefront.
 */
const auth = useAuthStore()
const cart = useCartStore()
const route = useRoute()
const mobileOpen = ref(false)
const search = ref('')

const nav = [
  { label: 'Women', to: '/women' },
  { label: 'Men', to: '/men' },
  { label: 'Wholesale', to: '/wholesale' },
]

function submitSearch() {
  if (!search.value.trim()) return
  mobileOpen.value = false
  navigateTo({ path: '/search', query: { q: search.value.trim() } })
}

// Navigating from the drawer swaps the page underneath it; leaving it open over
// the new route reads as a stuck menu.
watch(() => route.fullPath, () => { mobileOpen.value = false })

const firstName = computed(() => auth.user?.name?.split(' ')[0] ?? 'Account')
</script>

<template>
  <header>
    <!-- 1. Announcement bar -->
    <div class="bg-surface-warm px-4 py-2.5 text-center text-[13px] text-ink-700">
      Free shipping on orders over $600.
      <!-- The same invitation is the entire content of the sticky bar pinned to
           the bottom of a phone screen. Once is enough there. -->
      <NuxtLink
        v-if="!auth.isAuthenticated"
        to="/account/register"
        class="ml-1 hidden underline underline-offset-4 hover:text-ink-900 sm:inline"
      >
        Sign up to unlock wholesale pricing
      </NuxtLink>
    </div>

    <div class="sticky top-0 z-40 border-b border-edge-subtle bg-white">
      <!-- 2. Main bar -->
      <div class="container-wide flex h-16 items-center gap-2 md:h-[72px] md:gap-4">
        <!-- Icon-only controls are padded out to 44px (WCAG 2.5.5) and pulled
             back with negative margin so the row still reads as tight. -->
        <button
          class="-ml-2.5 grid size-11 shrink-0 place-items-center lg:hidden"
          :aria-expanded="mobileOpen"
          aria-label="Toggle menu"
          @click="mobileOpen = !mobileOpen"
        >
          <component :is="mobileOpen ? X : Menu" :size="22" />
        </button>

        <NuxtLink to="/" class="shrink-0" aria-label="BulkScrubsDirect — home">
          <!-- Tagline is dropped below md: the row is down to the menu, the
               wordmark and the cart there, and the name alone still identifies
               the site. -->
          <LayoutBrandMark size="sm" tagline-class="hidden md:block" />
        </NuxtLink>

        <form
          class="mx-auto hidden max-w-[560px] flex-1 md:block"
          role="search"
          @submit.prevent="submitSearch"
        >
          <div class="relative">
            <Search
              :size="16"
              class="absolute top-1/2 left-4 -translate-y-1/2 text-ink-400"
              aria-hidden="true"
            />
            <input
              v-model="search"
              type="search"
              aria-label="Search products"
              placeholder="Search for scrub sets"
              class="h-11 w-full rounded-full border border-edge bg-white pr-4 pl-11 text-sm text-ink-900 placeholder:text-ink-400 focus:border-edge-strong focus:outline-none"
            >
          </div>
        </form>

        <div class="ml-auto flex items-center gap-1 sm:gap-4">
          <NuxtLink
            v-if="auth.isAuthenticated"
            to="/account"
            class="hidden text-[13px] text-ink-700 hover:text-ink-900 sm:block"
          >
            {{ firstName }}
          </NuxtLink>
          <NuxtLink
            v-else
            to="/account/login"
            class="hidden text-[13px] text-ink-700 hover:text-ink-900 sm:block"
          >
            Sign in
          </NuxtLink>

          <!-- No account icon below sm. The wordmark plus three 44px targets
               does not fit a 320px screen, and the drawer directly below this
               button already carries the account link — the cart is the one
               that has to stay a single tap from every page. -->
          <NuxtLink
            to="/cart"
            class="-mr-2.5 grid size-11 place-items-center sm:mr-0 sm:size-auto"
            aria-label="Cart"
          >
            <span class="relative grid place-items-center">
              <ShoppingBag :size="20" />
              <span
                v-if="cart.itemCount > 0"
                class="tabular absolute -top-1.5 -right-2 flex size-[18px] items-center justify-center rounded-full bg-ink-900 text-[10px] font-medium text-white"
              >{{ cart.itemCount }}</span>
            </span>
          </NuxtLink>

          <UiBaseButton
            v-if="!auth.isAuthenticated"
            to="/account/register"
            size="sm"
            class="hidden lg:inline-flex"
          >
            Unlock wholesale pricing
          </UiBaseButton>
        </div>
      </div>

      <!-- 3. Category nav -->
      <nav class="hidden border-t border-edge-subtle lg:block" aria-label="Categories">
        <ul class="container-wide flex h-11 items-center justify-center gap-8">
          <li v-for="item in nav" :key="item.to">
            <NuxtLink
              :to="item.to"
              class="text-[13px] font-medium tracking-[0.02em] text-ink-700 hover:text-ink-900"
              active-class="text-ink-900 underline underline-offset-[6px]"
            >{{ item.label }}</NuxtLink>
          </li>
        </ul>
      </nav>

      <!-- Mobile drawer. Inside the sticky wrapper so it stays under the bar
           that opened it when the menu is used part-way down a page, and capped
           in height so a short phone can still scroll it. -->
      <div
        v-if="mobileOpen"
        class="max-h-[calc(100dvh-8rem)] overflow-y-auto border-t border-edge-subtle bg-white lg:hidden"
      >
        <div class="container-content py-4">
          <form role="search" @submit.prevent="submitSearch">
            <div class="relative">
              <Search
                :size="16"
                class="absolute top-1/2 left-4 -translate-y-1/2 text-ink-400"
                aria-hidden="true"
              />
              <input
                v-model="search"
                type="search"
                aria-label="Search products"
                placeholder="Search for scrub sets"
                class="h-11 w-full rounded-full border border-edge bg-white pr-4 pl-11 text-ink-900 placeholder:text-ink-400 focus:border-edge-strong focus:outline-none"
              >
            </div>
          </form>

          <ul class="mt-2 flex flex-col">
            <li v-for="item in nav" :key="item.to">
              <NuxtLink
                :to="item.to"
                class="flex min-h-11 items-center border-b border-edge-subtle text-[15px] text-ink-900"
                active-class="font-medium"
              >{{ item.label }}</NuxtLink>
            </li>
            <li>
              <NuxtLink
                :to="auth.isAuthenticated ? '/account' : '/account/login'"
                class="flex min-h-11 items-center border-b border-edge-subtle text-[15px] text-ink-900"
              >{{ auth.isAuthenticated ? `Hi, ${firstName}` : 'Sign in' }}</NuxtLink>
            </li>
          </ul>

          <UiBaseButton
            v-if="!auth.isAuthenticated"
            to="/account/register"
            size="md"
            block
            class="mt-4"
          >
            Unlock wholesale pricing
          </UiBaseButton>
        </div>
      </div>
    </div>
  </header>
</template>
