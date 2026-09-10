<script setup lang="ts">
import { Minus, Plus, Check } from 'lucide-vue-next'
import type { ProductDetail } from '~/composables/useApi'

const route = useRoute()
const api = useApi()
const cart = useCartStore()
const auth = useAuthStore()

const { data, error } = await useAsyncData(`product-${route.params.slug}`, () =>
  api.get<{ data: ProductDetail }>(`/products/${route.params.slug}`),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Product not found', fatal: true })
}

const product = computed(() => data.value!.data)

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

/** The variant matching the current colour + size selection. */
const variant = computed(() =>
  product.value.variants?.find(
    (v) => v.color_id === selectedColor.value && v.size_id === selectedSize.value,
  ) ?? null,
)

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
          offers: {
            '@type': 'Offer',
            priceCurrency: 'CAD',
            price: (product.value.retail_price.cents / 100).toFixed(2),
            availability: product.value.in_stock
              ? 'https://schema.org/InStock'
              : 'https://schema.org/OutOfStock',
          },
        }),
      ),
    },
  ],
})
</script>

<template>
  <div class="container-content pt-8">
    <nav aria-label="Breadcrumb" class="text-[12px] text-ink-400">
      <NuxtLink to="/" class="hover:text-ink-900">Home</NuxtLink>
      <span class="mx-1.5">/</span>
      <NuxtLink v-if="product.category" :to="`/${product.category.slug}`" class="hover:text-ink-900">
        {{ product.category.name }}
      </NuxtLink>
      <span class="mx-1.5">/</span>
      <span class="text-ink-700">{{ product.name }}</span>
    </nav>

    <div class="mt-6 grid gap-10 lg:grid-cols-2 lg:gap-14">
      <!-- Gallery -->
      <div class="lg:sticky lg:top-32 lg:self-start">
        <div class="aspect-4/5 overflow-hidden rounded-sm bg-surface-sunken">
          <img
            v-if="gallery[activeImage]"
            :src="gallery[activeImage].path"
            :alt="gallery[activeImage].alt"
            class="size-full object-cover"
          >
          <div
            v-else
            class="flex size-full items-center justify-center text-[11px] tracking-[0.12em] text-ink-400 uppercase"
          >
            Product photography to follow
          </div>
        </div>

        <div v-if="gallery.length > 1" class="mt-3 flex gap-2">
          <button
            v-for="(img, i) in gallery"
            :key="i"
            class="size-16 overflow-hidden rounded-sm border transition-colors"
            :class="i === activeImage ? 'border-edge-strong' : 'border-edge-subtle'"
            :aria-label="`View image ${i + 1}`"
            :aria-current="i === activeImage"
            @click="activeImage = i"
          >
            <img :src="img.path" :alt="img.alt" class="size-full object-cover">
          </button>
        </div>
      </div>

      <!-- Buying panel -->
      <div>
        <h1 class="font-display text-[32px] leading-tight text-ink-900">{{ product.name }}</h1>
        <p class="mt-1.5 text-[12px] tracking-[0.06em] text-ink-400 uppercase">
          SKU {{ product.base_sku }}
        </p>

        <div class="mt-5 space-y-2">
          <p class="tabular text-[24px] font-medium text-ink-900">
            {{ product.retail_price.formatted }}
          </p>
          <ShopWholesaleLock
            :locked="product.wholesale_locked"
            :wholesale-price="product.wholesale_from ?? null"
            :tier-name="entryTierName"
            :image="product.images?.[0] ?? null"
          />
          <p v-if="!auth.isAuthenticated" class="max-w-[52ch] text-[13px] text-ink-500">
            Wholesale pricing applies automatically once your order reaches $200.
            No business registration required.
          </p>
        </div>

        <ShopWholesaleLadder
          v-if="product.wholesale_tiers?.length"
          :tiers="product.wholesale_tiers"
          :active-tier-id="cart.tier?.id ?? null"
        />

        <p v-if="product.short_description" class="mt-5 max-w-[56ch] text-[15px] text-ink-700">
          {{ product.short_description }}
        </p>

        <!-- Colour -->
        <fieldset v-if="product.colors?.length" class="mt-7">
          <legend class="text-[12px] font-medium tracking-[0.04em] text-ink-900 uppercase">
            Colour:
            <span class="font-normal text-ink-500">
              {{ product.colors.find((c) => c.id === selectedColor)?.name }}
            </span>
          </legend>
          <div class="mt-3 flex flex-wrap gap-2">
            <button
              v-for="color in product.colors"
              :key="color.id"
              class="size-9 rounded-full border-2 transition-all"
              :class="selectedColor === color.id ? 'border-ink-900 ring-1 ring-ink-900 ring-offset-2' : 'border-edge'"
              :style="{ backgroundColor: color.hex || '#ddd' }"
              :aria-label="color.name"
              :aria-pressed="selectedColor === color.id"
              @click="selectedColor = color.id"
            />
          </div>
        </fieldset>

        <!-- Size. Availability is never signalled by colour alone (WCAG 1.4.1). -->
        <fieldset v-if="product.sizes?.length" class="mt-7">
          <div class="flex items-baseline justify-between">
            <legend class="text-[12px] font-medium tracking-[0.04em] text-ink-900 uppercase">
              Size
            </legend>
            <button
              v-if="product.size_chart"
              class="text-[13px] text-ink-700 underline underline-offset-4 hover:text-ink-900"
            >
              Size chart
            </button>
          </div>
          <div class="mt-3 flex flex-wrap gap-2">
            <button
              v-for="size in product.sizes"
              :key="size.id"
              :disabled="!sizeAvailability.get(size.id)"
              class="h-11 min-w-[52px] rounded-sm border px-3 text-[13px] font-medium transition-colors"
              :class="[
                selectedSize === size.id
                  ? 'border-ink-900 bg-ink-900 text-white'
                  : 'border-edge bg-white text-ink-900 hover:border-edge-strong',
                !sizeAvailability.get(size.id) && 'cursor-not-allowed !border-edge-subtle !bg-surface-sunken !text-ink-400 line-through',
              ]"
              :aria-pressed="selectedSize === size.id"
              :aria-label="sizeAvailability.get(size.id) ? size.name : `${size.name}, out of stock`"
              @click="selectedSize = size.id"
            >
              {{ size.name }}
            </button>
          </div>
        </fieldset>

        <!-- Quantity + add -->
        <div class="mt-7 flex flex-wrap items-center gap-3">
          <div class="flex h-11 items-center rounded-sm border border-edge">
            <button
              class="grid size-11 place-items-center text-ink-700 hover:text-ink-900 disabled:text-ink-400"
              :disabled="qty <= 1"
              aria-label="Decrease quantity"
              @click="qty = Math.max(1, qty - 1)"
            >
              <Minus :size="15" />
            </button>
            <span class="tabular w-10 text-center text-[15px] font-medium">{{ qty }}</span>
            <button
              class="grid size-11 place-items-center text-ink-700 hover:text-ink-900 disabled:text-ink-400"
              :disabled="variant ? qty >= variant.available : false"
              aria-label="Increase quantity"
              @click="qty = qty + 1"
            >
              <Plus :size="15" />
            </button>
          </div>

          <UiBaseButton
            size="lg"
            class="flex-1 sm:flex-none sm:min-w-[220px]"
            :disabled="!canAdd"
            :loading="adding"
            @click="addToCart"
          >
            <Check v-if="added" :size="16" />
            {{ added ? 'Added to cart' : !selectedSize ? 'Select a size' : canAdd ? 'Add to cart' : 'Out of stock' }}
          </UiBaseButton>
        </div>

        <p v-if="variant" class="mt-3 text-[13px]" :class="variant.low_stock ? 'text-status-warning' : 'text-ink-500'">
          <template v-if="variant.low_stock">Only {{ variant.available }} left</template>
          <template v-else-if="variant.available > 0">In stock</template>
        </p>

        <!-- Live tier feedback right where the decision is made. -->
        <ShopTierProgress v-if="!cart.isEmpty" :quote="cart.quote" class="mt-6" />

        <!-- The +/- panels §2 asks for. -->
        <div class="mt-10">
          <UiBaseAccordion v-if="product.panels.length" :panels="product.panels" />
        </div>
      </div>
    </div>
  </div>
</template>
