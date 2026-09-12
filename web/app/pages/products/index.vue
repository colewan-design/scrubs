<script setup lang="ts">
import type { ProductCard } from '~/composables/useApi'

/**
 * The whole catalogue in one grid.
 *
 * Added with the redesign because the design asks for two links that had
 * nowhere to point — "View All Products" under Featured Scrubs, and the
 * footer's Shop column — and because the header's search box was navigating to
 * /search, a route that has never existed. Both land here.
 *
 * Deliberately thinner than the category page: no facets, just sort and stock,
 * since this is the "show me everything" surface rather than the one people
 * refine in.
 */
const route = useRoute()
const api = useApi()

const search = computed(() => (typeof route.query.q === 'string' ? route.query.q : ''))
const sort = ref(String(route.query.sort ?? 'newest'))
const inStockOnly = ref(route.query.in_stock === '1')

const { data: products, pending } = await useAsyncData(
  'all-products',
  () =>
    api.get<{ data: ProductCard[]; meta?: any }>('/products', {
      search: search.value || undefined,
      sort: sort.value,
      in_stock: inStockOnly.value ? 1 : undefined,
      per_page: 60,
    }),
  { watch: [sort, inStockOnly, search] },
)

watch([sort, inStockOnly], () => {
  navigateTo(
    {
      query: {
        ...(search.value ? { q: search.value } : {}),
        ...(sort.value !== 'newest' ? { sort: sort.value } : {}),
        ...(inStockOnly.value ? { in_stock: '1' } : {}),
      },
    },
    { replace: true },
  )
})

useSeoMeta({
  title: () => (search.value ? `Search: ${search.value}` : 'All Products'),
  description:
    'Every scrub top, bottom and set in the BulkScrubs Direct catalogue — retail pricing for everyone, wholesale pricing once you are signed in.',
  // A search results page is not something to have indexed.
  robots: () => (search.value ? 'noindex' : undefined),
})

const sorts = [
  { value: 'newest', label: 'Newest' },
  { value: 'price_asc', label: 'Price: low to high' },
  { value: 'price_desc', label: 'Price: high to low' },
  { value: 'name', label: 'Name' },
]
</script>

<template>
  <div class="container-content pt-8">
    <nav aria-label="Breadcrumb" class="text-[12px] text-ink-400">
      <NuxtLink to="/" class="hover:text-ink-900">Home</NuxtLink>
      <span class="mx-1.5">/</span>
      <span class="text-ink-700">{{ search ? 'Search' : 'All Products' }}</span>
    </nav>

    <header class="mt-4">
      <h1 class="font-display text-[36px] text-ink-900">
        {{ search ? `Results for “${search}”` : 'All Products' }}
      </h1>
      <p v-if="!search" class="mt-2 max-w-[60ch] text-[15px] text-ink-500">
        Everything in the catalogue. Retail pricing is public — wholesale pricing
        appears as soon as you are signed in.
      </p>
    </header>

    <div class="mt-7 flex flex-wrap items-center gap-3 border-y border-edge-subtle py-3.5">
      <label class="flex cursor-pointer items-center gap-2 py-1 text-[13px] text-ink-700">
        <input
          v-model="inStockOnly"
          type="checkbox"
          class="size-4 rounded-sm border-edge accent-ink-900"
        >
        In stock only
      </label>

      <div class="ml-auto flex items-center gap-2">
        <label for="sort" class="text-[13px] text-ink-500">Sort</label>
        <select
          id="sort"
          v-model="sort"
          class="h-9 rounded-full border border-edge bg-white px-3.5 text-[13px] text-ink-900 focus:border-edge-strong focus:outline-none"
        >
          <option v-for="option in sorts" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </div>
    </div>

    <!-- Skeletons at the final dimensions, so nothing shifts on load. -->
    <div
      v-if="pending"
      class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6"
    >
      <div v-for="n in 12" :key="n" class="animate-pulse">
        <div class="aspect-4/5 rounded-md bg-surface-sunken" />
        <div class="mt-3 h-3.5 w-3/4 rounded-sm bg-surface-sunken" />
        <div class="mt-2 h-3.5 w-1/3 rounded-sm bg-surface-sunken" />
      </div>
    </div>

    <div
      v-else-if="products?.data?.length"
      class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6"
    >
      <ShopProductCard v-for="product in products.data" :key="product.id" :product="product" />
    </div>

    <div v-else class="mt-16 text-center">
      <p class="text-[15px] text-ink-700">
        {{ search ? 'Nothing matched that search.' : 'No products match these filters.' }}
      </p>
      <UiBaseButton
        variant="secondary"
        size="sm"
        class="mt-4"
        to="/products"
        @click="inStockOnly = false; sort = 'newest'"
      >
        Browse everything
      </UiBaseButton>
    </div>
  </div>
</template>
