<script setup lang="ts">
import { Package, ShieldCheck, Truck } from 'lucide-vue-next'

/**
 * About us (§11).
 *
 * The body copy is an admin-editable setting, for the same reason the policies
 * are — the final wording belongs to the client. Until it is written the page
 * falls back to a short description drawn from the project brief's own
 * description of the business, which is factual rather than invented.
 */
const api = useApi()

interface StoreContent {
  email: string | null
  about: string | null
}

const { data } = await useAsyncData('store-about', () => api.get<StoreContent>('/content/store'))

const about = computed(() => data.value?.about ?? null)

const fallback = [
  'BulkScrubs Direct is a Canadian scrub-uniform retailer and wholesale supplier based in Ontario.',
  'We serve two kinds of customer from one catalogue: individuals buying for themselves, and clinics, practices and resellers buying in volume. The range, the sizing and the stock are the same either way — the only thing that changes is the price you pay once your order is large enough.',
]

const pillars = [
  {
    icon: Package,
    title: 'One catalogue, two prices',
    body: 'Browse everything without an account. Create one to see wholesale pricing, and the cart applies the right tier automatically as your order grows.',
  },
  {
    icon: ShieldCheck,
    title: 'No application to fill in',
    body: 'You do not need to prove you run a registered business. Reaching the order minimum is what qualifies you — nothing else.',
  },
  {
    icon: Truck,
    title: 'Shipped across Canada',
    body: 'Delivery anywhere in Canada with tracking, or collect locally in Ontario. Shipping is free once your order passes the threshold.',
  },
]

useSeoMeta({
  title: 'About us',
  description:
    'BulkScrubs Direct is a Canadian scrub-uniform retailer and wholesale supplier serving individual and volume buyers from one catalogue.',
})
</script>

<template>
  <div class="container-content pt-10 pb-20">
    <div class="max-w-[68ch]">
      <h1 class="font-display text-[36px] leading-tight text-ink-900">About us</h1>

      <!-- Client-authored copy wins; the brief-derived summary is the fallback. -->
      <div v-if="about" class="mt-5 space-y-4 text-[16px] leading-relaxed whitespace-pre-line text-ink-700">
        {{ about }}
      </div>
      <div v-else class="mt-5 space-y-4">
        <p
          v-for="(para, i) in fallback"
          :key="i"
          class="text-[16px] leading-relaxed text-ink-700"
          :class="i === 0 && 'text-[18px] text-ink-900'"
        >
          {{ para }}
        </p>
      </div>
    </div>

    <section class="mt-14 border-t border-edge-subtle pt-10">
      <h2 class="font-display text-[24px] text-ink-900">How buying from us works</h2>

      <ul class="mt-7 grid gap-8 sm:grid-cols-3">
        <li v-for="pillar in pillars" :key="pillar.title" class="flex flex-col gap-2.5">
          <component :is="pillar.icon" :size="20" class="text-ink-400" aria-hidden="true" />
          <h3 class="text-[15px] font-medium text-ink-900">{{ pillar.title }}</h3>
          <p class="text-[14px] leading-relaxed text-ink-700">{{ pillar.body }}</p>
        </li>
      </ul>
    </section>

    <section class="mt-12 border-t border-edge-subtle pt-8">
      <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
        <NuxtLink
          to="/wholesale"
          class="text-[15px] text-ink-900 underline underline-offset-4 hover:opacity-75"
        >
          How wholesale pricing works
        </NuxtLink>
        <NuxtLink
          to="/contact"
          class="text-[15px] text-ink-900 underline underline-offset-4 hover:opacity-75"
        >
          Contact us
        </NuxtLink>
      </div>
    </section>
  </div>
</template>
