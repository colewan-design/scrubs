<script setup lang="ts">
import { Instagram, Facebook } from 'lucide-vue-next'

/**
 * Social links render only when supplied — they are outstanding from the client
 * (§14), so the component handles an empty set gracefully rather than showing
 * dead icons.
 *
 * Every link below points at a route that exists. The redesign's footer also
 * listed Best Sellers, Size Guide, FAQ and Careers; none of those has a page or
 * a data source yet, and a footer full of 404s is the specific bug this file
 * has already been fixed for once.
 */
const api = useApi()

const { data: store } = await useAsyncData('store-content', () =>
  api.get<{ social: Record<string, string> }>('/content/store'),
)

const socials = computed(() => {
  const configured = store.value?.social ?? {}
  return [
    { key: 'instagram', label: 'Instagram', icon: Instagram, href: configured.instagram },
    { key: 'facebook', label: 'Facebook', icon: Facebook, href: configured.facebook },
    // lucide ships no TikTok glyph, so that one is set as its own wordmark
    // rather than borrowed from an unrelated icon.
    { key: 'tiktok', label: 'TikTok', icon: null, href: configured.tiktok },
  ].filter((s) => Boolean(s.href))
})

const columns = [
  {
    title: 'Shop',
    links: [
      { label: 'Women', to: '/women' },
      { label: 'Men', to: '/men' },
      { label: 'New Arrivals', to: '/products?sort=newest' },
      { label: 'All Products', to: '/products' },
    ],
  },
  {
    title: 'Customer Care',
    links: [
      // The four policies live under /policies/{slug}, driven by admin-editable
      // wording (§7 of the materials request).
      { label: 'Shipping / Pickup', to: '/policies/shipping' },
      { label: 'Returns', to: '/policies/returns' },
      { label: 'Contact Us', to: '/contact' },
    ],
  },
  {
    title: 'About',
    links: [
      { label: 'Our Story', to: '/about' },
      { label: 'Wholesale / How It Works', to: '/wholesale' },
    ],
  },
]

const year = new Date().getFullYear()
</script>

<template>
  <footer class="border-t border-edge-subtle bg-surface-warm">
    <div class="container-content py-14">
      <div class="grid gap-10 lg:grid-cols-[minmax(0,1.3fr)_repeat(3,minmax(0,1fr))_minmax(0,1.1fr)]">
        <div>
          <LayoutBrandMark size="sm" />
          <p class="mt-3 max-w-[32ch] text-[13px] leading-relaxed text-ink-500">
            Scrubs for today. Stronger healthcare for tomorrow.
          </p>

          <ul v-if="socials.length" class="mt-6 flex items-center gap-2">
            <li v-for="social in socials" :key="social.key">
              <a
                :href="social.href"
                target="_blank"
                rel="noopener noreferrer"
                class="grid size-11 place-items-center rounded-full text-ink-700 hover:bg-surface-warm-deep hover:text-ink-900"
                :aria-label="social.label"
              >
                <component :is="social.icon" v-if="social.icon" :size="19" aria-hidden="true" />
                <span v-else class="text-[10px] font-semibold tracking-[0.04em]">TikTok</span>
              </a>
            </li>
          </ul>
        </div>

        <div v-for="col in columns" :key="col.title">
          <h2 class="font-body text-[13px] font-semibold text-ink-900">
            {{ col.title }}
          </h2>
          <!-- py-1 on the anchor, not the row: a 13px line box is a 16px tap
               target, under the 24px WCAG 2.5.8 floor, and these stack close
               enough on a phone to mis-tap. -->
          <ul class="mt-3 space-y-1">
            <li v-for="link in col.links" :key="link.to">
              <NuxtLink
                :to="link.to"
                class="inline-block py-1 text-[13px] text-ink-500 hover:text-ink-900"
              >
                {{ link.label }}
              </NuxtLink>
            </li>
          </ul>
        </div>

        <div class="lg:border-l lg:border-edge-subtle lg:pl-10">
          <p class="flex items-center gap-2 text-[13px] font-semibold text-ink-900">
            <span class="text-[15px]" aria-hidden="true">🍁</span>
            Proudly Canadian
          </p>
          <p class="mt-1.5 max-w-[30ch] text-[13px] leading-relaxed text-ink-500">
            Supporting the healthcare community across Canada.
          </p>
        </div>
      </div>
    </div>

    <div class="border-t border-edge-subtle">
      <div
        class="container-content flex flex-wrap items-center justify-between gap-x-6 gap-y-2 py-5 text-[12px] text-ink-400"
      >
        <span>© {{ year }} BulkScrubs Direct. All rights reserved. All prices in CAD.</span>
        <nav class="flex flex-wrap items-center gap-x-6 gap-y-1" aria-label="Legal">
          <NuxtLink to="/policies/privacy" class="py-1 hover:text-ink-900">Privacy Policy</NuxtLink>
          <NuxtLink to="/policies/terms" class="py-1 hover:text-ink-900">Terms of Service</NuxtLink>
          <NuxtLink to="/contact" class="py-1 hover:text-ink-900">Contact</NuxtLink>
        </nav>
      </div>
    </div>
  </footer>
</template>
