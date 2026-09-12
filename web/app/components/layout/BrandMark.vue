<script setup lang="ts">
/**
 * The wordmark, in one place so the header and footer cannot drift apart.
 *
 * The redesign drops the BSD monogram tile that used to sit beside the name:
 * the lockup is now the name alone, set heavy and tight, which is what carries
 * at both header and footer size without a second element competing with it.
 *
 * "BulkScrubs Direct" is set as two words here, matching the redesign. The
 * domain and the <title> suffix are still the solid "BulkScrubsDirect" — see
 * app.vue if that should be reconciled.
 *
 * Still a placeholder until the client's logo arrives (§14 / materials request
 * §3), which is why it is drawn in type rather than shipped as an asset —
 * swapping in a real .svg means editing this component only.
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
  size?: 'sm' | 'md' | 'lg'
}>(), {
  tagline: false,
  taglineClass: '',
  size: 'md',
})

const sizes: Record<string, string> = {
  sm: 'text-[21px]',
  md: 'text-[25px]',
  lg: 'text-[30px]',
}
</script>

<template>
  <span class="inline-flex flex-col leading-none">
    <span
      class="font-body font-bold tracking-[-0.02em] whitespace-nowrap text-ink-900"
      :class="sizes[size]"
    >BulkScrubs Direct</span>

    <span
      v-if="tagline"
      class="mt-1.5 whitespace-nowrap font-body text-[9px] tracking-[0.16em] text-ink-500 uppercase"
      :class="taglineClass"
    >Wholesale Scrub Uniforms</span>
  </span>
</template>
