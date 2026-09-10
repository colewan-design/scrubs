<script setup lang="ts">
/**
 * Sub-navigation shared by every /account page (§8).
 *
 * A horizontal rail rather than a sidebar: there are four destinations, and a
 * sidebar would spend a quarter of a phone screen on them. aria-current is what
 * tells a screen reader which one is open — the underline alone would not.
 */
const route = useRoute()

const links = [
  { to: '/account', label: 'Overview' },
  { to: '/account/orders', label: 'Orders' },
  { to: '/account/addresses', label: 'Addresses' },
  { to: '/account/profile', label: 'Profile' },
]

const isCurrent = (to: string) => route.path === to
</script>

<template>
  <nav aria-label="Account" class="border-b border-edge-subtle">
    <ul class="-mb-px flex gap-6 overflow-x-auto">
      <li v-for="link in links" :key="link.to" class="shrink-0">
        <NuxtLink
          :to="link.to"
          :aria-current="isCurrent(link.to) ? 'page' : undefined"
          class="block border-b-2 pb-3 text-[14px] transition-colors"
          :class="isCurrent(link.to)
            ? 'border-ink-900 font-medium text-ink-900'
            : 'border-transparent text-ink-500 hover:text-ink-900'"
        >
          {{ link.label }}
        </NuxtLink>
      </li>
    </ul>
  </nav>
</template>
