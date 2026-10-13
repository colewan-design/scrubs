<script setup lang="ts">
import { ZoomIn, Play, X } from 'lucide-vue-next'

/**
 * Product page gallery: a vertical thumbnail rail beside the main image.
 *
 * The rail moves below the image under `sm`, where an 80px column costs more
 * width than the thumbnails return — `flex-col-reverse` keeps the image first
 * in the visual order there while the rail stays first in the DOM, so tabbing
 * still reaches the thumbnails before the controls layered over the image.
 */
const props = withDefaults(defineProps<{
  images: Array<{ path: string; alt: string }>
  /** Draws the video tile at the end of the rail. See the TODO below. */
  hasVideo?: boolean
}>(), { hasVideo: false })

const active = defineModel<number>('active', { default: 0 })

const zoomOpen = ref(false)

// The redesign draws a wishlist heart over the image's top-right corner. It was
// dropped rather than shipped: there is no wishlist in the API or in §8, and
// the control stored nothing.

const current = computed(() => props.images[active.value] ?? null)

// The zoom overlay is a real dialog, so it gets the focus trap and the Escape
// handler from the platform rather than from a hand-rolled key listener.
const zoomDialog = ref<HTMLDialogElement | null>(null)

watch(zoomOpen, (open) => {
  const el = zoomDialog.value
  if (!el) return
  if (open && !el.open) el.showModal()
  else if (!open && el.open) el.close()
})
</script>

<template>
  <div class="flex flex-col-reverse gap-3 sm:flex-row sm:gap-4">
    <!-- Thumbnail rail -->
    <div
      v-if="images.length > 1 || hasVideo"
      class="flex shrink-0 gap-2 overflow-x-auto pb-1 sm:flex-col sm:overflow-x-visible sm:pb-0"
    >
      <button
        v-for="(img, i) in images"
        :key="i"
        class="aspect-4/5 w-16 shrink-0 overflow-hidden rounded-sm border transition-colors sm:w-20"
        :class="i === active ? 'border-edge-strong' : 'border-edge-subtle hover:border-edge'"
        :aria-label="`View image ${i + 1} of ${images.length}`"
        :aria-current="i === active ? 'true' : undefined"
        @click="active = i"
      >
        <img :src="img.path" :alt="img.alt" class="size-full object-cover">
      </button>

      <!-- TODO(video): products carry no video field yet, so the tile is drawn
           and disabled rather than linked to nothing. -->
      <button
        v-if="hasVideo"
        type="button"
        disabled
        title="Product video coming soon"
        class="flex aspect-4/5 w-16 shrink-0 cursor-not-allowed flex-col items-center justify-center gap-1.5
               rounded-sm border border-edge-subtle bg-surface-sunken sm:w-20"
      >
        <span class="grid size-7 place-items-center rounded-full bg-ink-900/80 text-white">
          <Play :size="12" fill="currentColor" aria-hidden="true" />
        </span>
        <span class="text-[10px] leading-none text-ink-500">Watch Video</span>
      </button>
    </div>

    <!-- Main image -->
    <div class="relative min-w-0 flex-1">
      <div class="aspect-4/5 overflow-hidden rounded-sm bg-surface-sunken">
        <img
          v-if="current"
          :src="current.path"
          :alt="current.alt"
          class="size-full object-cover"
        >
        <div
          v-else
          class="flex size-full items-center justify-center text-[11px] tracking-[0.12em] text-ink-400 uppercase"
        >
          Product photography to follow
        </div>
      </div>

      <button
        v-if="current"
        type="button"
        class="absolute right-3 bottom-3 flex items-center gap-1.5 rounded-full bg-white/95 px-3 py-2
               text-[12px] text-ink-700 shadow-pop transition-colors hover:text-ink-900"
        @click="zoomOpen = true"
      >
        <ZoomIn :size="14" aria-hidden="true" />
        Click to zoom
      </button>
    </div>

    <!-- Zoom overlay -->
    <dialog
      ref="zoomDialog"
      class="m-auto max-h-[92dvh] max-w-[92vw] bg-transparent backdrop:bg-ink-900/80"
      aria-label="Enlarged product image"
      @close="zoomOpen = false"
      @click.self="zoomOpen = false"
    >
      <div class="relative">
        <img
          v-if="current"
          :src="current.path"
          :alt="current.alt"
          class="max-h-[92dvh] max-w-[92vw] rounded-sm object-contain"
        >
        <button
          type="button"
          class="absolute top-3 right-3 grid size-10 place-items-center rounded-full bg-white/95 text-ink-900"
          aria-label="Close"
          @click="zoomOpen = false"
        >
          <X :size="18" aria-hidden="true" />
        </button>
      </div>
    </dialog>
  </div>
</template>
