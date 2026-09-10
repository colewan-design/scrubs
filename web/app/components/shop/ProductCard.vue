<script setup lang="ts">
import type { ProductCard } from '~/composables/useApi'

/**
 * The most-repeated component on the site.
 *
 * No border and no shadow — the image *is* the card, which is what the
 * reference design does. Only the wholesale line differs between signed-in and
 * signed-out, so the grid never reflows on login.
 *
 * The card is not wrapped in a link. It uses the stretched-link pattern: the
 * title's link carries an ::after that covers the whole tile, so the entire
 * card is clickable while only the title text names the destination. That keeps
 * the lock a real <button> — nesting one inside an <a> is invalid HTML and
 * breaks hydration, which is why the lock used to be inert here.
 */
defineProps<{ product: ProductCard }>()
</script>

<template>
  <div class="group relative">
    <div class="relative aspect-4/5 overflow-hidden rounded-sm bg-surface-sunken">
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

      <div class="absolute top-2.5 left-2.5 flex flex-col gap-1.5">
        <UiBaseBadge v-if="product.is_featured" tone="neutral">New</UiBaseBadge>
        <UiBaseBadge v-if="product.in_stock === false" tone="dark">Sold out</UiBaseBadge>
      </div>
    </div>

    <div class="mt-3 space-y-1.5">
      <h3 class="line-clamp-2 font-body text-[15px] leading-snug font-normal text-ink-900">
        <NuxtLink
          :to="`/products/${product.slug}`"
          class="after:absolute after:inset-0 after:content-['']"
        >
          {{ product.name }}
        </NuxtLink>
      </h3>

      <!-- Colour swatches, capped with a +N overflow. -->
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

      <p class="text-[15px] font-medium text-ink-900 tabular">
        {{ product.retail_price.formatted }}
      </p>

      <!-- Lifted above the title link's overlay so the lock takes its own click. -->
      <ShopWholesaleLock
        :locked="product.wholesale_locked"
        :wholesale-price="product.wholesale_from"
        :image="product.image"
        compact
        class="relative z-10"
      />
    </div>
  </div>
</template>
