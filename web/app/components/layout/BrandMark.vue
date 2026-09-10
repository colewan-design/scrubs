<script setup lang="ts">
/**
 * The wordmark, in one place so the header and footer cannot drift apart.
 *
 * The lockup is the BSD monogram, "BulkScrubsDirect" set solid as one word, and
 * the descriptor beneath. The name is deliberately NOT letterspaced or upper-cased
 * here: it is a single word matching the domain, and tracking it out would read
 * as three.
 *
 * A placeholder until the client's logo arrives (§14 / materials request §3),
 * which is why it is drawn in type rather than shipped as an asset — swapping
 * in a real .svg means editing this component only.
 */
withDefaults(defineProps<{
  /** Hidden in tight spots; the name alone still identifies the site. */
  tagline?: boolean
  /**
   * Classes for the tagline only. The header drops it below md, and it has to
   * be done here rather than by hiding a second copy of the whole lockup from
   * the outside: this component's root is `inline-flex`, so a `hidden` passed
   * in as a fallthrough class loses to it on source order and both copies paint.
   */
  taglineClass?: string
  size?: 'sm' | 'md'
}>(), {
  tagline: true,
  taglineClass: '',
  size: 'md',
})
</script>

<template>
  <span class="inline-flex items-center gap-3">
    <span
      class="grid shrink-0 place-items-center rounded-[6px] border border-ink-900/25
             font-display tracking-[0.06em] text-ink-900"
      :class="size === 'sm' ? 'size-8 text-[11px]' : 'size-11 text-[13px]'"
      aria-hidden="true"
    >BSD</span>

    <span class="flex flex-col leading-none">
      <span
        class="font-display whitespace-nowrap text-ink-900"
        :class="size === 'sm' ? 'text-[19px]' : 'text-[25px]'"
      >BulkScrubsDirect</span>

      <span
        v-if="tagline"
        class="mt-1 whitespace-nowrap font-body text-ink-500 uppercase"
        :class="[
          size === 'sm' ? 'text-[9px] tracking-[0.16em]' : 'text-[10px] tracking-[0.2em]',
          taglineClass,
        ]"
      >Wholesale Scrub Uniforms</span>
    </span>
  </span>
</template>
