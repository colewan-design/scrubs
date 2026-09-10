<script setup lang="ts">
/**
 * Social links render only when supplied — they are outstanding from the client
 * (§14), so the component handles an empty set gracefully rather than showing
 * dead icons.
 */
const columns = [
  {
    title: 'Shop',
    links: [
      { label: 'Women’s Scrubs', to: '/women' },
      { label: 'Men’s Scrubs', to: '/men' },
      { label: 'Wholesale', to: '/wholesale' },
    ],
  },
  {
    title: 'Help',
    links: [
      { label: 'Contact', to: '/contact' },
      // The four policies live under /policies/{slug}, driven by admin-editable
      // wording (§7 of the materials request). These pointed at routes that do
      // not exist, so every one of them 404'd.
      { label: 'Shipping & Pickup', to: '/policies/shipping' },
      { label: 'Returns & Refunds', to: '/policies/returns' },
    ],
  },
  {
    title: 'Company',
    links: [
      { label: 'About Us', to: '/about' },
      { label: 'Privacy Policy', to: '/policies/privacy' },
      { label: 'Terms & Conditions', to: '/policies/terms' },
    ],
  },
]

const year = new Date().getFullYear()
</script>

<template>
  <footer class="mt-20 border-t border-edge-subtle bg-surface-warm">
    <div class="container-content grid gap-10 py-14 md:grid-cols-4">
      <div>
        <LayoutBrandMark size="sm" />
        <p class="mt-3 max-w-[30ch] text-[13px] leading-relaxed text-ink-500">
          Canadian scrub uniforms for individuals, teams and resellers.
        </p>
      </div>

      <div v-for="col in columns" :key="col.title">
        <h2 class="font-body text-[11px] font-semibold tracking-[0.08em] text-ink-900 uppercase">
          {{ col.title }}
        </h2>
        <!-- py-1 on the anchor, not the row: a 13px line box is a 16px tap
             target, under the 24px WCAG 2.5.8 floor, and these stack close
             enough on a phone to mis-tap. -->
        <ul class="mt-2 space-y-1">
          <li v-for="link in col.links" :key="link.to">
            <NuxtLink :to="link.to" class="inline-block py-1 text-[13px] text-ink-500 hover:text-ink-900">
              {{ link.label }}
            </NuxtLink>
          </li>
        </ul>
      </div>
    </div>

    <div class="border-t border-edge-subtle">
      <div class="container-content flex flex-wrap justify-between gap-2 py-5 text-[12px] text-ink-400">
        <span>© {{ year }} BulkScrubsDirect. All prices in CAD.</span>
        <span>Ontario, Canada</span>
      </div>
    </div>
  </footer>
</template>
