<script setup lang="ts">
import { Lock, ArrowRight } from 'lucide-vue-next'
import type { ProductCard } from '~/composables/useApi'

/**
 * §1: "Homepage should initially emphasize two primary shopping paths:
 * WOMEN and MEN." The split hero makes that structural rather than merely
 * stated, and keeps a two-category catalogue from looking sparse.
 */
const api = useApi()
const auth = useAuthStore()

const { data: featured } = await useAsyncData('home-featured', () =>
  api.get<{ data: ProductCard[] }>('/products', { per_page: 12 })
)

const { data: wholesale } = await useAsyncData('home-wholesale', () =>
  api.get<any>('/wholesale')
)

useSeoMeta({
  title: 'Wholesale Scrubs in Canada',
  description:
    'Canadian scrub uniforms for individuals, teams and resellers. Unlock wholesale pricing on orders over $200. Free shipping over $600.',
})

const paths = [
  {
    label: 'Women',
    to: '/women',
    blurb: 'Sets, tops and bottoms designed for a woman’s fit.',
    tint: 'from-clay/12',
  },
  {
    label: 'Men',
    to: '/men',
    blurb: 'Sets, tops and bottoms designed for a man’s fit.',
    tint: 'from-sage/12',
  },
]
</script>

<template>
  <div>
    <!-- Split hero. Photography with a light tint rather than a dark fill:
         §1 explicitly rules out large dark background sections. -->
    <section class="container-wide pt-6">
      <div class="grid gap-3 md:grid-cols-2">
        <NuxtLink
          v-for="path in paths"
          :key="path.to"
          :to="path.to"
          class="group relative flex min-h-[340px] flex-col justify-end overflow-hidden rounded-md border border-edge-subtle bg-surface-warm p-8 md:min-h-[420px]"
        >
          <div
            class="absolute inset-0 bg-gradient-to-tr to-transparent transition-opacity duration-300 group-hover:opacity-80"
            :class="path.tint"
            aria-hidden="true"
          />
          <div class="relative">
            <h2 class="font-display text-[38px] leading-none text-ink-900 md:text-[46px]">
              {{ path.label }}
            </h2>
            <p class="mt-2 max-w-[34ch] text-[15px] text-ink-700">{{ path.blurb }}</p>
            <span
              class="mt-5 inline-flex items-center gap-2 text-[13px] font-medium tracking-[0.02em] text-ink-900"
            >
              Shop {{ path.label.toLowerCase() }}
              <ArrowRight
                :size="15"
                class="transition-transform duration-150 group-hover:translate-x-1"
                aria-hidden="true"
              />
            </span>
          </div>
        </NuxtLink>
      </div>
    </section>

    <!-- Wholesale ladder. Real tier data, never hardcoded copy. -->
    <section class="container-content pt-20">
      <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 class="font-display text-[28px] text-ink-900">Buy more, pay less</h2>
          <p class="mt-1.5 max-w-[56ch] text-[15px] text-ink-500">
            Wholesale pricing is automatic — no application, no approval. Reach a
            threshold and the cart applies the tier for you.
          </p>
        </div>
        <NuxtLink
          to="/wholesale"
          class="text-[13px] font-medium text-ink-900 underline underline-offset-4"
        >
          How it works
        </NuxtLink>
      </div>

      <div class="mt-6 grid gap-3 sm:grid-cols-3">
        <div
          v-for="tier in wholesale?.tiers ?? []"
          :key="tier.slug"
          class="rounded-sm border border-edge-subtle bg-surface-warm p-5"
        >
          <p class="text-[11px] font-semibold tracking-[0.08em] text-ink-500 uppercase">
            {{ tier.name }}
          </p>
          <p class="mt-2 font-display text-[26px] text-ink-900">
            <template v-if="!tier.locked && tier.unit_price">
              {{ tier.unit_price.formatted }}<span class="text-[14px] text-ink-500">/set</span>
            </template>
            <span v-else class="inline-flex items-center gap-2 text-[20px] text-sage">
              <Lock :size="16" aria-hidden="true" /> Locked
            </span>
          </p>
          <p class="mt-2 text-[13px] text-ink-700">
            From <span class="tabular font-medium">{{ tier.min_subtotal.formatted }}</span>
            <template v-if="tier.min_qty"> or {{ tier.min_qty }} units</template>
          </p>
        </div>
      </div>

      <p v-if="!auth.isAuthenticated" class="mt-4 text-[13px] text-ink-500">
        <NuxtLink to="/account/register" class="text-sage underline underline-offset-4">
          Create a free account
        </NuxtLink>
        to see wholesale prices. It takes about a minute, and no business
        registration is required.
      </p>
    </section>

    <!-- Product grid: 6 across at 2xl, 2 across on mobile so browsing stays
         efficient on a phone (§1 names mobile as a priority). -->
    <section class="container-content pt-20">
      <h2 class="font-display text-[28px] text-ink-900">Top products</h2>
      <div
        class="mt-6 grid grid-cols-2 gap-x-4 gap-y-9 md:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6"
      >
        <ShopProductCard
          v-for="product in featured?.data ?? []"
          :key="product.id"
          :product="product"
        />
      </div>
    </section>
  </div>
</template>
