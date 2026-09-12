<script setup lang="ts">
import { Home, LogOut, MapPin, Package, User } from 'lucide-vue-next'

/**
 * The frame every /account page sits in (§8): who is signed in, where they can
 * go, and the page's own heading.
 *
 * A sidebar rather than the horizontal rail this replaced — the redesign draws
 * one, and it has room for the identity block and the sign-out that the rail
 * never did. Below lg it collapses back to a scrolling rail, because a sidebar
 * there would spend a quarter of a phone screen on four links.
 */
defineProps<{ title: string; subtitle?: string }>()

const auth = useAuthStore()
const route = useRoute()

const links = [
  { to: '/account', label: 'Overview', icon: Home },
  { to: '/account/orders', label: 'Orders', icon: Package },
  { to: '/account/addresses', label: 'Addresses', icon: MapPin },
  { to: '/account/profile', label: 'Account Details', icon: User },
]

const isCurrent = (to: string) => route.path === to

/** Two letters at most — "Jamie Smith" is JS, a one-word name is its first. */
const initials = computed(() => {
  const parts = (auth.user?.name ?? '').split(' ').filter(Boolean)

  return parts.slice(0, 2).map((p) => p[0]!.toUpperCase()).join('') || '·'
})

/** The year alone. The exact date is on the account details page. */
const memberSince = computed(() => {
  const raw = auth.user?.member_since
  if (!raw) return null

  const year = new Date(raw).getFullYear()

  return Number.isNaN(year) ? null : year
})

const loggingOut = ref(false)

async function logout() {
  loggingOut.value = true
  try {
    await auth.logout()
    await navigateTo('/')
  } finally {
    loggingOut.value = false
  }
}
</script>

<template>
  <div class="container-content pt-10 pb-20">
    <div class="grid gap-8 lg:grid-cols-[248px_minmax(0,1fr)] lg:gap-12">
      <!-- min-w-0: a grid item sizes to its content by default, which lets the
           scrolling nav rail push the whole page sideways on a phone instead of
           scrolling inside itself. -->
      <aside class="min-w-0 lg:border-r lg:border-edge-subtle lg:pr-6">
        <div class="flex items-center gap-3">
          <span
            class="grid size-11 shrink-0 place-items-center rounded-full bg-surface-warm-deep
                   text-[14px] font-semibold text-ink-700"
            aria-hidden="true"
          >
            {{ initials }}
          </span>
          <div class="min-w-0">
            <p class="text-[12px] text-ink-500">Welcome back,</p>
            <p class="truncate text-[15px] font-medium text-ink-900">{{ auth.user?.name }}</p>
            <p v-if="memberSince" class="text-[12px] text-ink-400">Customer since {{ memberSince }}</p>
          </div>
        </div>

        <nav aria-label="Account" class="mt-6">
          <ul class="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
            <li v-for="link in links" :key="link.to" class="shrink-0">
              <NuxtLink
                :to="link.to"
                :aria-current="isCurrent(link.to) ? 'page' : undefined"
                class="flex items-center gap-3 rounded-sm px-3 py-2.5 text-[14px] transition-colors lg:w-full"
                :class="isCurrent(link.to)
                  ? 'bg-surface-warm font-medium text-ink-900'
                  : 'text-ink-700 hover:bg-surface-sunken hover:text-ink-900'"
              >
                <component
                  :is="link.icon"
                  :size="18"
                  class="shrink-0"
                  :class="isCurrent(link.to) ? 'text-ink-900' : 'text-ink-500'"
                  aria-hidden="true"
                />
                {{ link.label }}
              </NuxtLink>
            </li>

            <!-- Sign-out sits with the destinations because the redesign puts it
                 there, divided off so it is never mistaken for one. -->
            <li class="shrink-0 lg:mt-2 lg:w-full lg:border-t lg:border-edge-subtle lg:pt-2">
              <button
                class="flex w-full items-center gap-3 rounded-sm px-3 py-2.5 text-[14px] text-ink-700
                       transition-colors hover:bg-surface-sunken hover:text-ink-900 disabled:opacity-60"
                :disabled="loggingOut"
                @click="logout"
              >
                <LogOut :size="18" class="shrink-0 text-ink-500" aria-hidden="true" />
                Logout
              </button>
            </li>
          </ul>
        </nav>
      </aside>

      <div class="min-w-0">
        <h1 class="font-display text-[32px] text-ink-900">{{ title }}</h1>
        <p v-if="subtitle" class="mt-1.5 text-[15px] text-ink-500">{{ subtitle }}</p>

        <div class="mt-7">
          <slot />
        </div>
      </div>
    </div>
  </div>
</template>
