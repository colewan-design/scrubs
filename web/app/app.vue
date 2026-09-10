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

useHead({
  titleTemplate: (title?: string) =>
    title && title !== BRAND ? `${title} · ${BRAND}` : BRAND,
})
</script>

<template>
  <NuxtLayout>
    <NuxtPage />
  </NuxtLayout>
</template>
