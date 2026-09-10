<script setup lang="ts">
/**
 * "Continue with Google" (§3).
 *
 * One component for the sign-in page, the registration page and the unlock
 * dialog, because the three have to behave identically: the same providers, the
 * same wording, the same brand marks. Google's brand guidelines require their
 * own logo on their own button, which is why the mark is inline SVG rather than
 * an icon from the general set.
 *
 * Still driven by the API's provider list rather than a single hardcoded
 * button: Apple was dropped on 2026-09-04, but Google is equally invisible
 * until credentials exist, and that has to stay true without a code change.
 *
 * Renders nothing at all when no provider is configured, so the divider above
 * it does not end up floating over an empty space.
 */
const props = withDefaults(defineProps<{
  /** "Continue with" reads right for signing in, "Sign up with" for joining. */
  verb?: string
  divider?: boolean
}>(), {
  verb: 'Continue with',
  divider: true,
})

const { has, hasAny, signInWith } = useAuthProviders()

const buttonClass =
  'flex h-11 w-full items-center justify-center gap-2.5 rounded-sm border border-edge ' +
  'text-[15px] font-medium text-ink-900 transition-colors hover:bg-surface-warm ' +
  'focus:border-edge-strong focus:outline-none'
</script>

<template>
  <div v-if="hasAny" class="space-y-3">
    <div v-if="props.divider" class="flex items-center gap-3">
      <span class="h-px flex-1 bg-edge-subtle" />
      <span class="text-[12px] tracking-[0.06em] text-ink-400 uppercase">or</span>
      <span class="h-px flex-1 bg-edge-subtle" />
    </div>

    <!-- Leaves the SPA entirely, so a plain button rather than a submit. -->
    <button v-if="has('google')" type="button" :class="buttonClass" @click="signInWith('google')">
      <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
        <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62Z" />
        <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.83.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18Z" />
        <path fill="#FBBC05" d="M3.97 10.72a5.41 5.41 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33Z" />
        <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.9 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58Z" />
      </svg>
      {{ props.verb }} Google
    </button>
  </div>
</template>
