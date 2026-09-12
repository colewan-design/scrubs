<script setup lang="ts">
import { Search, ShoppingBag, Menu, X, Tag, User } from 'lucide-vue-next'

/**
 * Two stacked rows, per the redesign:
 *   1. announcement bar (warm ground) — offers left, positioning right
 *   2. main bar — wordmark · search · nav · account · cart
 *
 * The old third row (a separate centred category strip) is gone: with the nav
 * sitting in the main bar there is nothing left for it to carry, and it cost a
 * 44px band of every viewport.
 *
 * Row 2 sticks on scroll; the announcement bar detaches.
 *
 * Below lg the nav collapses into the drawer, which also carries the search
 * field — the main bar has no room for it next to the wordmark at 390px, and
 * dropping search entirely is not an option on a storefront.
 */
const auth = useAuthStore()
const cart = useCartStore()
const route = useRoute()
const mobileOpen = ref(false)
const search = ref('')

// Thresholds come from admin-editable settings, never from copy typed here.
const { minOrder, freeShipping } = await useWholesaleSummary()

const nav = [
  { label: 'Women', to: '/women' },
  { label: 'Men', to: '/men' },
  { label: 'Wholesale / How It Works', to: '/wholesale' },
  { label: 'About', to: '/about' },
  { label: 'Contact', to: '/contact' },
]

function submitSearch() {
  if (!search.value.trim()) return
  mobileOpen.value = false
  navigateTo({ path: '/products', query: { q: search.value.trim() } })
}

// Navigating from the drawer swaps the page underneath it; leaving it open over
// the new route reads as a stuck menu.
watch(() => route.fullPath, () => { mobileOpen.value = false })

const firstName = computed(() => auth.user?.name?.split(' ')[0] ?? 'Account')
</script>

<template>
  <header>
    <!-- 1. Announcement bar. Two clusters that collapse to one centred line on
         a phone, where only the offers half survives — the positioning line is
         the first thing that can go. -->
    <div class="border-b border-edge-subtle bg-surface-warm text-[12px] text-ink-700">
      <div
        class="container-wide flex h-9 items-center justify-center gap-x-6 md:justify-between"
      >
        <p class="flex items-center gap-x-2 truncate">
          <Tag :size="13" class="hidden shrink-0 sm:block" aria-hidden="true" />
          <span v-if="minOrder">
            Unlock wholesale pricing from CAD {{ minOrder.formatted }}+
          </span>
          <span class="hidden text-edge md:inline" aria-hidden="true">|</span>
          <span v-if="freeShipping" class="hidden md:inline">
            Free shipping on qualifying orders CAD {{ freeShipping.formatted }}+
          </span>
        </p>

        <p class="hidden items-center gap-x-2 truncate lg:flex">
          <!-- Emoji rather than an icon: lucide has no maple leaf, and a hand
               rolled path would be the one piece of fabricated brand art on
               the site. -->
          <span aria-hidden="true">🍁</span>
          <span>Proudly Canadian</span>
          <span class="text-edge" aria-hidden="true">|</span>
          <span>Better Teams. Healthier Communities.</span>
        </p>
      </div>
    </div>

    <div class="sticky top-0 z-40 border-b border-edge-subtle bg-white">
      <!-- 2. Main bar -->
      <div class="container-wide flex h-16 items-center gap-2 md:h-[76px] md:gap-4">
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

        <NuxtLink to="/" class="shrink-0" aria-label="BulkScrubs Direct — home">
          <LayoutBrandMark size="sm" />
        </NuxtLink>

        <form
          class="hidden max-w-[420px] flex-1 md:block xl:max-w-[520px]"
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
              placeholder="Search scrubs, colours, brands and more..."
              class="h-11 w-full rounded-full border border-edge bg-white pr-4 pl-11 text-sm text-ink-900 placeholder:text-ink-400 focus:border-edge-strong focus:outline-none"
            >
          </div>
        </form>

        <!-- Nav sits in the main bar now. It is the row's flexible member, so a
             long label ("Wholesale / How It Works") squeezes the gaps rather
             than pushing the cart off the end. -->
        <nav class="hidden min-w-0 flex-1 lg:block" aria-label="Primary">
          <ul class="flex items-center justify-end gap-5 xl:gap-7">
            <li v-for="item in nav" :key="item.to">
              <NuxtLink
                :to="item.to"
                class="text-[13px] font-medium whitespace-nowrap text-ink-700 hover:text-ink-900"
                active-class="text-ink-900 underline underline-offset-[6px]"
              >{{ item.label }}</NuxtLink>
            </li>
          </ul>
        </nav>

        <div class="ml-auto flex items-center gap-1 sm:gap-4 lg:ml-6">
          <NuxtLink
            :to="auth.isAuthenticated ? '/account' : '/account/login'"
            class="hidden items-center gap-2 text-[13px] text-ink-700 hover:text-ink-900 sm:flex"
          >
            <User :size="19" aria-hidden="true" />
            {{ auth.isAuthenticated ? firstName : 'Sign In' }}
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
        </div>
      </div>

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
                placeholder="Search scrubs, colours, brands and more..."
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
