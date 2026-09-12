<script setup lang="ts">
import { SlidersHorizontal } from 'lucide-vue-next'
import type { ProductCard } from '~/composables/useApi'

/**
 * Category listing with filtering and sorting — near-mandatory for apparel,
 * and not in the original brief (flagged as a gap during analysis).
 *
 * Carries the product page's redesign: the same eyebrow-over-serif-heading
 * masthead, the same card (which now owns the wishlist control), and the same
 * trust band closing the page.
 */
const route = useRoute()
const api = useApi()

const slug = computed(() => String(route.params.category))

// Only real category slugs render here; anything else is a 404 rather than an
// empty grid, so bad URLs do not look like an empty catalogue.
const { data: category, error } = await useAsyncData(
  () => `category-${slug.value}`,
  () => api.get<any>(`/categories/${slug.value}`),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Category not found', fatal: true })
}

const sort = ref(String(route.query.sort ?? 'newest'))
const inStockOnly = ref(route.query.in_stock === '1')

const { data: products, pending } = await useAsyncData(
  () => `products-${slug.value}`,
  () =>
    api.get<{ data: ProductCard[]; meta?: any }>('/products', {
      category: slug.value,
      sort: sort.value,
      in_stock: inStockOnly.value ? 1 : undefined,
      per_page: 24,
    }),
  { watch: [sort, inStockOnly] },
)

watch([sort, inStockOnly], () => {
  navigateTo(
    {
      query: {
        ...(sort.value !== 'newest' ? { sort: sort.value } : {}),
        ...(inStockOnly.value ? { in_stock: '1' } : {}),
      },
    },
    { replace: true },
  )
})

/** Server-side total, so it reflects the filters rather than the page size. */
const total = computed<number | null>(() => products.value?.meta?.total ?? null)

const isFiltered = computed(() => inStockOnly.value || sort.value !== 'newest')

function clearFilters() {
  inStockOnly.value = false
  sort.value = 'newest'
}

useSeoMeta({
  title: () => category.value?.data?.meta?.title ?? 'Scrubs',
  description: () => category.value?.data?.meta?.description ?? undefined,
})

const sorts = [
  { value: 'newest', label: 'Newest' },
  { value: 'price_asc', label: 'Price: low to high' },
  { value: 'price_desc', label: 'Price: high to low' },
  { value: 'name', label: 'Name' },
]
</script>

<template>
  <div class="pt-6">
    <div class="container-content">
      <nav aria-label="Breadcrumb" class="text-[12px] text-ink-400">
        <NuxtLink to="/" class="hover:text-ink-900">Home</NuxtLink>
        <span class="mx-1.5" aria-hidden="true">/</span>
        <span class="text-ink-700">{{ category?.data?.name }}</span>
      </nav>

      <!-- Masthead, matching the product page's eyebrow-over-heading lockup. -->
      <header class="mt-5">
        <p class="text-[11px] font-medium tracking-[0.12em] text-ink-400 uppercase">Shop</p>
        <h1 class="mt-1.5 font-display text-[32px] text-ink-900 sm:text-[38px]">
          {{ category?.data?.name }}
        </h1>
        <p v-if="category?.data?.description" class="mt-2.5 max-w-[62ch] text-[15px] text-ink-500">
          {{ category.data.description }}
        </p>
      </header>

      <!-- Filter row. Pills are the one place radius goes fully round. -->
      <div class="mt-7 flex flex-wrap items-center gap-x-4 gap-y-3 border-y border-edge-subtle py-3.5">
        <SlidersHorizontal :size="15" class="hidden shrink-0 text-ink-400 sm:block" aria-hidden="true" />

        <label class="flex cursor-pointer items-center gap-2 py-1 text-[13px] text-ink-700">
          <input
            v-model="inStockOnly"
            type="checkbox"
            class="size-4 rounded-sm border-edge accent-ink-900"
          >
          In stock only
        </label>

        <button
          v-if="isFiltered"
          type="button"
          class="text-[13px] text-ink-500 underline underline-offset-4 hover:text-ink-900"
          @click="clearFilters"
        >Clear</button>

        <!-- aria-live so a filter change is announced; the grid itself swaps
             too much at once to be a useful live region. -->
        <p
          v-if="total !== null"
          class="tabular text-[13px] text-ink-400"
          aria-live="polite"
        >{{ total }} {{ total === 1 ? 'product' : 'products' }}</p>

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
        class="mt-8 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6"
      >
        <div v-for="n in 12" :key="n" class="animate-pulse">
          <div class="aspect-4/5 rounded-md bg-surface-sunken" />
          <div class="mt-3 h-3.5 w-3/4 rounded-sm bg-surface-sunken" />
          <div class="mt-2 h-3.5 w-1/3 rounded-sm bg-surface-sunken" />
        </div>
      </div>

      <div
        v-else-if="products?.data?.length"
        class="mt-8 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6"
      >
        <ShopProductCard v-for="product in products.data" :key="product.id" :product="product" />
      </div>

      <!-- One explanation, one action. Empty filter results will be common on a
           two-category launch catalogue. -->
      <div v-else class="mt-16 text-center">
        <p class="text-[15px] text-ink-700">No products match these filters.</p>
        <UiBaseButton variant="secondary" size="sm" class="mt-4" @click="clearFilters">
          Clear filters
        </UiBaseButton>
      </div>

      <!-- Same band the product page closes its buy box with, so the promises
           are in the same words in both places a shopper meets them. -->
      <ShopTrustRow class="mt-14 sm:mt-16" />
    </div>
  </div>
</template>
