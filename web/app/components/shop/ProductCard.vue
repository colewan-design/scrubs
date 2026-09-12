<script setup lang="ts">
import { Heart } from 'lucide-vue-next'
import type { ProductCard } from '~/composables/useApi'

/**
 * The most-repeated component on the site.
 *
 * The redesign puts the tile in a hairline frame — image, then swatches, name,
 * price, and the wholesale strip along the bottom. Only the wholesale line
 * differs between signed-in and signed-out, so the grid never reflows on login.
 *
 * The card is not wrapped in a link. It uses the stretched-link pattern: the
 * title's link carries an ::after that covers the whole tile, so the entire
 * card is clickable while only the title text names the destination. That keeps
 * the lock a real <button> — nesting one inside an <a> is invalid HTML and
 * breaks hydration, which is why the lock used to be inert here.
 */
defineProps<{ product: ProductCard }>()

/**
 * TODO(wishlist): drawn on the tile because the redesign draws it there, and
 * deliberately inert — no wishlist exists in the API or in §8. Sits above the
 * title's stretched-link overlay so it takes its own click rather than
 * navigating, the same arrangement the wholesale lock below uses.
 */
const wishlisted = ref(false)
</script>

<template>
  <div
    class="group relative flex flex-col overflow-hidden rounded-md border border-edge-subtle bg-white transition-colors hover:border-edge"
  >
    <div class="relative aspect-4/5 overflow-hidden bg-surface-sunken">
      <img
        v-if="product.image"
        :src="product.image.path"
        :alt="product.image.alt"
        loading="lazy"
        class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
        :class="product.in_stock === false && 'opacity-60 saturate-50'"
      >
      <!-- Placeholder until the client's photography arrives (§14). -->
      <div
        v-else
        class="flex size-full items-center justify-center text-[11px] uppercase
               tracking-[0.12em] text-ink-400"
      >
        Image to follow
      </div>

      <button
        type="button"
        class="absolute top-2.5 right-2.5 z-10 grid size-8 place-items-center rounded-full bg-white/90
               text-ink-500 transition-colors hover:text-ink-900"
        :aria-pressed="wishlisted"
        :aria-label="wishlisted ? `Remove ${product.name} from wishlist` : `Save ${product.name} to wishlist`"
        @click="wishlisted = !wishlisted"
      >
        <Heart :size="15" :fill="wishlisted ? 'currentColor' : 'none'" aria-hidden="true" />
      </button>

      <div class="absolute top-2.5 left-2.5 flex flex-col gap-1.5">
        <UiBaseBadge v-if="product.is_featured" tone="neutral">New</UiBaseBadge>
        <UiBaseBadge v-if="product.in_stock === false" tone="dark">Sold out</UiBaseBadge>
      </div>
    </div>

    <div class="flex flex-1 flex-col gap-1.5 p-3">
      <!-- Colour swatches, capped with a +N overflow. Above the name, as drawn:
           colour is what a shopper scans a scrubs grid for. -->
      <div v-if="product.colors?.length" class="flex items-center gap-1">
        <span
          v-for="c in product.colors.slice(0, 5)"
          :key="c.slug"
          class="size-3.5 rounded-full border border-edge"
          :style="{ backgroundColor: c.hex || '#ddd' }"
          :title="c.name"
        />
        <span v-if="product.colors.length > 5" class="ml-0.5 text-[11px] text-ink-400">
          +{{ product.colors.length - 5 }}
        </span>
      </div>

      <h3 class="line-clamp-2 font-body text-[14px] leading-snug font-normal text-ink-900">
        <NuxtLink
          :to="`/products/${product.slug}`"
          class="after:absolute after:inset-0 after:content-['']"
        >
          {{ product.name }}
        </NuxtLink>
      </h3>

      <!-- Currency stated rather than assumed: the store prices in CAD and the
           redesign says so on every tile. -->
      <p class="tabular text-[15px] font-semibold text-ink-900">
        {{ product.retail_price.currency }} {{ product.retail_price.formatted }}
      </p>

      <!-- Lifted above the title link's overlay so the lock takes its own click. -->
      <ShopWholesaleLock
        :locked="product.wholesale_locked"
        :wholesale-price="product.wholesale_from"
        :image="product.image"
        compact
        class="relative z-10 mt-auto"
      />
    </div>
  </div>
</template>
