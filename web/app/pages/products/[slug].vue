<script setup lang="ts">
import { Minus, Plus, Check, ShoppingCart, Lock, Ruler, ArrowRight, X } from 'lucide-vue-next'
import type { ProductCard, ProductDetail } from '~/composables/useApi'

const route = useRoute()
const api = useApi()
const cart = useCartStore()
const auth = useAuthStore()
const { show: showUnlock } = useUnlockModal()

const { data, error } = await useAsyncData(`product-${route.params.slug}`, () =>
  api.get<{ data: ProductDetail }>(`/products/${route.params.slug}`),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Product not found', fatal: true })
}

const product = computed(() => data.value!.data)

/**
 * TODO(reviews): there is no reviews table, endpoint or admin resource — §2
 * never asked for one. The row is drawn at the figures the redesign shows so
 * the layout is final; replace both constants with the real aggregate before
 * this ships, or remove the row. Nothing here is persisted or submitted.
 */
const PLACEHOLDER_RATING = 4.8
const PLACEHOLDER_REVIEW_COUNT = 124

/**
 * The rung `wholesale_from` refers to. Named beside the price so the figure is
 * never a bare number without the threshold that earns it.
 */
const entryTierName = computed(() => product.value.wholesale_tiers?.[0]?.name ?? null)

const selectedColor = ref<number | null>(product.value.colors?.[0]?.id ?? null)
const selectedSize = ref<number | null>(null)
const qty = ref(1)
const adding = ref(false)
const added = ref(false)

/**
 * The redesign draws eight swatches and a "+N more". Deeper palettes expand in
 * place rather than scrolling: a shopper choosing a colour wants them all side
 * by side, and this is the one control on the page that decides the imagery.
 */
const SWATCH_LIMIT = 8
const allColorsShown = ref(false)
const visibleColors = computed(() => {
  const colors = product.value.colors ?? []
  return allColorsShown.value ? colors : colors.slice(0, SWATCH_LIMIT)
})
const hiddenColorCount = computed(() =>
  Math.max(0, (product.value.colors?.length ?? 0) - SWATCH_LIMIT),
)

const selectedColorName = computed(
  () => product.value.colors?.find((c) => c.id === selectedColor.value)?.name ?? null,
)
const selectedSizeName = computed(
  () => product.value.sizes?.find((s) => s.id === selectedSize.value)?.name ?? null,
)

/** The variant matching the current colour + size selection. */
const variant = computed(() =>
  product.value.variants?.find(
    (v) => v.color_id === selectedColor.value && v.size_id === selectedSize.value,
  ) ?? null,
)

/**
 * What the shopper will actually be charged.
 *
 * The cart prices from the variant, so the page has to as well — sizes can
 * carry an upcharge, and showing the product's own figure would quote a price
 * the basket then disagrees with. Before a size is chosen we show the cheapest
 * variant, labelled "from".
 */
const displayPrice = computed(
  () => variant.value?.retail_price ?? product.value.retail_price_from ?? product.value.retail_price,
)

/** Only while no specific variant is pinned down, and only if they differ. */
const priceIsFrom = computed(() => !variant.value && product.value.retail_price_varies === true)

/**
 * Availability per size for the currently selected colour. Out-of-stock sizes
 * stay visible but disabled — a shopper needs to see that their size exists
 * and is unavailable, rather than wondering whether it is offered at all (§2).
 */
const sizeAvailability = computed(() => {
  const map = new Map<number, number>()
  for (const v of product.value.variants ?? []) {
    if (v.color_id === selectedColor.value) map.set(v.size_id, v.available)
  }
  return map
})

const gallery = computed(() => {
  const images = product.value.images ?? []
  const colorSlug = product.value.colors?.find((c) => c.id === selectedColor.value)?.slug
  const forColor = images.filter((i) => i.color_slug === colorSlug)
  return forColor.length ? forColor : images
})

const activeImage = ref(0)
watch(selectedColor, () => { activeImage.value = 0 })

/**
 * No size is preselected, deliberately — the redesign draws one chosen, but
 * defaulting the control that decides what arrives in the box turns a shopper's
 * omission into a wrong-size order. Until a size is picked the stock line falls
 * back to the product-level flag, so the row still reads as drawn.
 */
const stockLabel = computed(() => {
  if (variant.value) {
    if (variant.value.available <= 0) return { text: 'Out of stock', tone: 'bad' as const }
    if (variant.value.low_stock) {
      return { text: `Only ${variant.value.available} left`, tone: 'low' as const }
    }
    return { text: 'In stock – ready to ship', tone: 'good' as const }
  }
  if (product.value.in_stock === false) return { text: 'Out of stock', tone: 'bad' as const }
  return { text: 'In stock – ready to ship', tone: 'good' as const }
})

/** Variant SKU once the selection resolves to one, the base SKU before that. */
const displaySku = computed(() => variant.value?.sku ?? product.value.base_sku)

const canAdd = computed(() => variant.value !== null && variant.value.available > 0)

async function addToCart() {
  if (!canAdd.value) return
  adding.value = true
  try {
    await cart.add(variant.value!.id, qty.value)
    added.value = true
    setTimeout(() => (added.value = false), 2200)
  } finally {
    adding.value = false
  }
}

// --- size chart ------------------------------------------------------------
// Native <dialog>, matching UnlockModal: focus trap, Escape and the top layer
// all come from the platform rather than from a hand-rolled overlay.
const sizeChartDialog = ref<HTMLDialogElement | null>(null)
const sizeChartOpen = ref(false)

watch(sizeChartOpen, (open) => {
  const el = sizeChartDialog.value
  if (!el) return
  if (open && !el.open) el.showModal()
  else if (!open && el.open) el.close()
})

/**
 * The size chart is its own API field, not one of the `panels` the resource
 * builds, so it is appended here — §2 lists it alongside the other three and
 * the redesign draws it as the fourth row.
 */
const panels = computed(() => {
  const list = [...product.value.panels]
  if (product.value.size_chart) {
    list.push({ key: 'size-chart', label: 'Size Chart', body: product.value.size_chart.body })
  }
  return list
})

// --- you may also like -----------------------------------------------------
/**
 * No recommendations endpoint exists, so "related" means the rest of the
 * category. Over-fetched by one because the current product is filtered out
 * client-side, which keeps this a plain catalogue query rather than a new route.
 */
const RELATED_LIMIT = 6
const { data: relatedResponse } = await useAsyncData(
  () => `related-${route.params.slug}`,
  () =>
    product.value.category
      ? api.get<{ data: ProductCard[] }>('/products', {
          category: product.value.category.slug,
          per_page: RELATED_LIMIT + 1,
        })
      : Promise.resolve({ data: [] as ProductCard[] }),
)

const related = computed(() =>
  (relatedResponse.value?.data ?? [])
    .filter((p) => p.id !== product.value.id)
    .slice(0, RELATED_LIMIT),
)

useSeoMeta({
  title: () => product.value.meta.title,
  description: () => product.value.meta.description ?? undefined,
})

// Product structured data for search results.
useHead({
  script: [
    {
      type: 'application/ld+json',
      innerHTML: computed(() =>
        JSON.stringify({
          '@context': 'https://schema.org',
          '@type': 'Product',
          name: product.value.name,
          sku: product.value.base_sku,
          description: product.value.short_description,
          // AggregateOffer once sizes disagree on price. Publishing a single
          // figure for a product whose 3XL costs more is a price mismatch
          // Google will flag, and a shopper arriving from search would land on
          // a page quoting something else.
          offers: product.value.retail_price_varies
            ? {
                '@type': 'AggregateOffer',
                priceCurrency: 'CAD',
                lowPrice: (
                  (product.value.retail_price_from ?? product.value.retail_price).cents / 100
                ).toFixed(2),
                highPrice: (
                  (product.value.retail_price_to ?? product.value.retail_price).cents / 100
                ).toFixed(2),
                offerCount: product.value.variants?.length ?? 0,
                availability: product.value.in_stock
                  ? 'https://schema.org/InStock'
                  : 'https://schema.org/OutOfStock',
              }
            : {
                '@type': 'Offer',
                priceCurrency: 'CAD',
                price: (
                  (product.value.retail_price_from ?? product.value.retail_price).cents / 100
                ).toFixed(2),
                availability: product.value.in_stock
                  ? 'https://schema.org/InStock'
                  : 'https://schema.org/OutOfStock',
              },
          // Deliberately no aggregateRating: the figures on the page are
          // placeholders, and marking them up would put invented review counts
          // into search results. Add it back with the reviews feature.
        }),
      ),
    },
  ],
})
</script>

<template>
  <div class="pt-6">
    <div class="container-content">
      <nav aria-label="Breadcrumb" class="text-[12px] text-ink-400">
        <NuxtLink to="/" class="hover:text-ink-900">Home</NuxtLink>
        <span class="mx-1.5" aria-hidden="true">/</span>
        <NuxtLink
          v-if="product.category"
          :to="`/${product.category.slug}`"
          class="hover:text-ink-900"
        >{{ product.category.name }}</NuxtLink>
        <span v-if="product.category" class="mx-1.5" aria-hidden="true">/</span>
        <span class="text-ink-700">{{ product.name }}</span>
      </nav>

      <div class="mt-5 grid gap-10 lg:grid-cols-2 lg:gap-14">
        <!-- Gallery -->
        <div class="lg:sticky lg:top-28 lg:self-start">
          <ShopProductGallery v-model:active="activeImage" :images="gallery" has-video />
        </div>

        <!-- Buying panel -->
        <div>
          <!-- The redesign's eyebrow reads "Classic Collection". Products carry
               no collection field, so this states the category instead — the
               same slot, filled with something the admin can actually edit. -->
          <p
            v-if="product.category"
            class="text-[11px] font-medium tracking-[0.12em] text-ink-400 uppercase"
          >{{ product.category.name }}</p>

          <h1 class="mt-1.5 font-display text-[30px] leading-tight text-ink-900 sm:text-[34px]">
            {{ product.name }}
          </h1>

          <div class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1">
            <ShopStarRating :rating="PLACEHOLDER_RATING" :count="PLACEHOLDER_REVIEW_COUNT" />
            <span class="text-edge" aria-hidden="true">|</span>
            <!-- Inert until reviews exist — see the TODO above. -->
            <button
              type="button"
              disabled
              title="Reviews are coming soon"
              class="cursor-not-allowed text-[13px] text-ink-500 underline underline-offset-4"
            >Write a review</button>
          </div>

          <p class="tabular mt-4 text-[26px] font-medium text-ink-900">
            <span v-if="priceIsFrom" class="text-[15px] font-normal text-ink-500">from </span
            >{{ displayPrice.currency }} {{ displayPrice.formatted }}
          </p>

          <p v-if="product.short_description" class="mt-2 max-w-[56ch] text-[14px] text-ink-500">
            {{ product.short_description }}
          </p>

          <!-- Wholesale. Guests get the boxed pitch; members get the figure and
               the full ladder, which is the thing the box is promising. -->
          <div class="mt-5">
            <ShopWholesaleLock
              :locked="product.wholesale_locked"
              :wholesale-price="product.wholesale_from ?? null"
              :tier-name="entryTierName"
              :image="product.images?.[0] ?? null"
              panel
            />
            <ShopWholesaleLadder
              v-if="product.wholesale_tiers?.length"
              :tiers="product.wholesale_tiers"
              :active-tier-id="cart.tier?.id ?? null"
            />
          </div>

          <!-- Colour -->
          <fieldset v-if="product.colors?.length" class="mt-7">
            <legend class="text-[12px] font-medium tracking-[0.04em] text-ink-900 uppercase">
              Colour:
              <span class="font-normal text-ink-500">{{ selectedColorName }}</span>
            </legend>
            <div class="mt-3 flex flex-wrap items-center gap-2.5">
              <button
                v-for="color in visibleColors"
                :key="color.id"
                type="button"
                class="size-9 rounded-full border transition-all"
                :class="selectedColor === color.id
                  ? 'border-ink-900 ring-1 ring-ink-900 ring-offset-2'
                  : 'border-edge hover:border-edge-strong'"
                :style="{ backgroundColor: color.hex || '#ddd' }"
                :aria-label="color.name"
                :aria-pressed="selectedColor === color.id"
                @click="selectedColor = color.id"
              />
              <button
                v-if="hiddenColorCount && !allColorsShown"
                type="button"
                class="text-[13px] text-ink-500 underline underline-offset-4 hover:text-ink-900"
                @click="allColorsShown = true"
              >+{{ hiddenColorCount }} more</button>
            </div>
          </fieldset>

          <!-- Size. Availability is never signalled by colour alone (WCAG 1.4.1). -->
          <fieldset v-if="product.sizes?.length" class="mt-7">
            <div class="flex items-baseline justify-between gap-3">
              <legend class="text-[12px] font-medium tracking-[0.04em] text-ink-900 uppercase">
                Size<span v-if="selectedSizeName" class="font-normal text-ink-500">:
                  {{ selectedSizeName }}</span>
              </legend>
              <button
                v-if="product.size_chart"
                type="button"
                class="flex shrink-0 items-center gap-1.5 text-[13px] text-ink-700 underline underline-offset-4 hover:text-ink-900"
                @click="sizeChartOpen = true"
              >
                <Ruler :size="14" aria-hidden="true" />
                Size Guide
              </button>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
              <button
                v-for="size in product.sizes"
                :key="size.id"
                type="button"
                :disabled="!sizeAvailability.get(size.id)"
                class="h-11 min-w-[56px] rounded-sm border px-3 text-[13px] font-medium transition-colors"
                :class="[
                  selectedSize === size.id
                    ? 'border-ink-900 bg-ink-900 text-white'
                    : 'border-edge bg-white text-ink-900 hover:border-edge-strong',
                  !sizeAvailability.get(size.id) && 'cursor-not-allowed !border-edge-subtle !bg-surface-sunken !text-ink-400 line-through',
                ]"
                :aria-pressed="selectedSize === size.id"
                :aria-label="sizeAvailability.get(size.id) ? size.name : `${size.name}, out of stock`"
                @click="selectedSize = size.id"
              >{{ size.name }}</button>
            </div>
          </fieldset>

          <!-- Stock + SKU. The dot is decorative; the words carry the state. -->
          <div class="mt-5 flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5">
            <p
              class="flex items-center gap-2 text-[13px]"
              :class="{
                'text-status-success': stockLabel.tone === 'good',
                'text-status-warning': stockLabel.tone === 'low',
                'text-ink-500': stockLabel.tone === 'bad',
              }"
            >
              <span class="size-2 shrink-0 rounded-full bg-current" aria-hidden="true" />
              {{ stockLabel.text }}
            </p>
            <p class="text-[12px] text-ink-400">SKU: {{ displaySku }}</p>
          </div>

          <!-- Quantity + actions -->
          <div class="mt-4 flex items-start gap-3">
            <div class="flex h-12 shrink-0 items-center rounded-sm border border-edge">
              <button
                type="button"
                class="grid size-11 place-items-center text-ink-700 hover:text-ink-900 disabled:text-ink-400"
                :disabled="qty <= 1"
                aria-label="Decrease quantity"
                @click="qty = Math.max(1, qty - 1)"
              >
                <Minus :size="15" />
              </button>
              <span class="tabular w-8 text-center text-[15px] font-medium">{{ qty }}</span>
              <button
                type="button"
                class="grid size-11 place-items-center text-ink-700 hover:text-ink-900 disabled:text-ink-400"
                :disabled="variant ? qty >= variant.available : false"
                aria-label="Increase quantity"
                @click="qty = qty + 1"
              >
                <Plus :size="15" />
              </button>
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-2.5">
              <UiBaseButton
                size="lg"
                block
                :disabled="!canAdd"
                :loading="adding"
                @click="addToCart"
              >
                <component :is="added ? Check : ShoppingCart" :size="17" aria-hidden="true" />
                {{ added ? 'Added to cart' : !selectedSize ? 'Select a size' : canAdd ? 'Add to Cart' : 'Out of stock' }}
              </UiBaseButton>

              <UiBaseButton
                v-if="!auth.isAuthenticated"
                variant="secondary"
                size="lg"
                block
                @click="showUnlock({ image: product.images?.[0] ?? null })"
              >
                <Lock :size="15" aria-hidden="true" />
                Unlock Wholesale Pricing
              </UiBaseButton>
            </div>
          </div>

          <!-- Live tier feedback right where the decision is made. -->
          <ShopTierProgress v-if="!cart.isEmpty" :quote="cart.quote" class="mt-6" />

          <ShopTrustRow class="mt-7" />

          <!-- The +/- panels §2 asks for. -->
          <UiBaseAccordion v-if="panels.length" :panels="panels" class="mt-2" />
        </div>
      </div>
    </div>

    <!-- You may also like -->
    <section v-if="related.length" class="mt-16 bg-surface-sunken py-12 sm:mt-20 sm:py-14">
      <div class="container-content">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h2 class="font-display text-[26px] text-ink-900">You May Also Like</h2>
            <p class="mt-1 text-[13px] text-ink-500">
              Popular styles that pair perfectly with this {{ product.product_type }}.
            </p>
          </div>
          <NuxtLink
            to="/products"
            class="flex items-center gap-2 text-[13px] font-medium text-ink-900 underline-offset-4 hover:underline"
          >
            View All Products
            <ArrowRight :size="15" aria-hidden="true" />
          </NuxtLink>
        </div>

        <div class="mt-7 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
          <ShopProductCard v-for="item in related" :key="item.id" :product="item" />
        </div>
      </div>
    </section>

    <!-- Size chart -->
    <dialog
      v-if="product.size_chart"
      ref="sizeChartDialog"
      class="m-auto w-[min(92vw,640px)] rounded-md bg-white p-0 backdrop:bg-ink-900/60"
      aria-labelledby="size-chart-title"
      @close="sizeChartOpen = false"
    >
      <div class="flex items-start justify-between gap-4 border-b border-edge-subtle px-5 py-4">
        <h2 id="size-chart-title" class="font-display text-[20px] text-ink-900">
          {{ product.size_chart.name }}
        </h2>
        <button
          type="button"
          class="-mt-1 -mr-2 grid size-9 shrink-0 place-items-center text-ink-500 hover:text-ink-900"
          aria-label="Close"
          @click="sizeChartOpen = false"
        >
          <X :size="18" aria-hidden="true" />
        </button>
      </div>
      <!-- Body is admin-authored copy, not markup. -->
      <div
        class="max-h-[70dvh] overflow-y-auto px-5 py-4 text-[14px] leading-relaxed whitespace-pre-line text-ink-700"
      >{{ product.size_chart.body }}</div>
    </dialog>
  </div>
</template>
