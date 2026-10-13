<script setup lang="ts">
/**
 * The title suffix lives here, not in nuxt.config.
 *
 * `app.head` is serialised into the payload, so a FUNCTION titleTemplate there
 * is silently dropped — which is why every tab read "Wholesale Pricing" with no
 * sign of whose site it was. A function is what lets the home page stand as
 * just the brand instead of "BulkScrubsDirect · BulkScrubsDirect".
 */
const BRAND = 'BulkScrubsDirect'

/**
 * One address per page, said out loud.
 *
 * The same page answers at www and at the bare domain, and with any query
 * string a filter, a campaign link or a receipt adds. A canonical link tells a
 * search engine which of those is the page, so they are not indexed as several
 * thin copies of each other. Built from the configured site URL and the path
 * alone — never from the Host header, which is the very thing that varies.
 */
const route = useRoute()
const siteUrl = useRuntimeConfig().public.siteUrl.replace(/\/+$/, '')
const canonical = computed(() => siteUrl + (route.path === '/' ? '/' : route.path.replace(/\/+$/, '')))

useHead({
  titleTemplate: (title?: string) =>
    title && title !== BRAND ? `${title} · ${BRAND}` : BRAND,
  link: [{ rel: 'canonical', href: canonical }],
})

useSeoMeta({ ogUrl: canonical, ogType: 'website' })
</script>

<template>
  <NuxtLayout>
    <NuxtPage />
  </NuxtLayout>
</template>
