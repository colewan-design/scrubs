<script setup lang="ts">
import { X } from 'lucide-vue-next'

/**
 * The wholesale sign-up prompt (§3), opened by every lock on the site.
 *
 * Built on the native <dialog> element, which supplies the focus trap, the
 * Escape handler, the inert background and the top layer — all of the things a
 * hand-rolled overlay gets subtly wrong. Nothing here renders a wholesale
 * price: this dialog is only ever seen by someone who is not signed in.
 *
 * Only an email is asked for. The full form lives on /account/register and the
 * address is carried across, so the shopper types it exactly once.
 */
const { isOpen, context, hide } = useUnlockModal()

const dialog = ref<HTMLDialogElement | null>(null)
const email = ref('')

/**
 * showModal() is what puts the element in the top layer and traps focus —
 * toggling an `open` attribute does neither. It must be called from the client
 * only, and never twice, or the browser throws.
 */
watch(isOpen, (open) => {
  const el = dialog.value
  if (!el) return

  if (open && !el.open) {
    el.showModal()
  } else if (!open && el.open) {
    el.close()
  }
})

// Escape and the browser's own dismissal both fire `close` without going
// through hide(), so the shared state is synced back here rather than assumed.
function onClose() {
  isOpen.value = false
  email.value = ''
}

/** A click landing on the dialog itself is a click on the backdrop. */
function onBackdropClick(event: MouseEvent) {
  if (event.target === dialog.value) hide()
}

function submit() {
  const address = email.value.trim()
  hide()
  return navigateTo({
    path: '/account/register',
    query: address ? { email: address } : undefined,
  })
}

function goToSignIn() {
  const address = email.value.trim()
  hide()
  return navigateTo({
    path: '/account/login',
    query: address ? { email: address } : undefined,
  })
}
</script>

<template>
  <!-- m-auto is what centres a modal <dialog>: the UA stylesheet pins it to all
       four edges and lets `margin: auto` do the rest, but Tailwind's preflight
       zeroes that margin, which parks the dialog in the top-left corner. -->
  <dialog
    ref="dialog"
    aria-labelledby="unlock-modal-title"
    class="m-auto w-[min(92vw,26rem)] rounded-sm border border-edge-subtle bg-white p-0
           text-ink-900 backdrop:bg-ink-900/40 open:animate-none"
    @close="onClose"
    @click="onBackdropClick"
  >
    <div class="relative px-8 pt-8 pb-7">
      <button
        type="button"
        class="absolute top-3.5 right-3.5 p-2 text-ink-400 transition-colors hover:text-ink-900"
        aria-label="Close"
        @click="hide"
      >
        <X :size="18" />
      </button>

      <!-- The product the shopper was looking at when they hit the lock. Purely
           decorative — the heading already names the offer. -->
      <img
        v-if="context.image"
        :src="context.image.path"
        alt=""
        class="mx-auto mb-6 size-32 rounded-sm object-cover"
      >

      <h2 id="unlock-modal-title" class="text-center font-display text-[26px] leading-tight">
        Unlock wholesale pricing
      </h2>
      <p class="mt-2 text-center text-[14px] leading-relaxed text-ink-500">
        Free to join. No business registration and nobody to wait on — your
        order size sets your price.
      </p>

      <form class="mt-6 space-y-3" novalidate @submit.prevent="submit">
        <div>
          <label for="unlock-modal-email" class="block text-[13px] font-medium text-ink-900">
            Business email
          </label>
          <input
            id="unlock-modal-email"
            v-model="email"
            type="email"
            name="email"
            autocomplete="email"
            class="mt-1.5 h-11 w-full rounded-sm border border-edge-strong px-3 text-[15px]
                   outline-none focus-visible:border-ink-900"
          >
        </div>

        <UiBaseButton type="submit" size="lg" block>Sign up for free</UiBaseButton>
      </form>

      <!-- Sits inside the dialog rather than only on /account/register: a
           shopper who signs up with Google here never sees a form at all,
           which is the shortest path from the lock to the price. -->
      <AuthSocialButtons class="mt-3" verb="Sign up with" :divider="false" />

      <!-- TODO(§14): these become links once the client supplies the policy
           wording and the pages exist. Plain text until then, rather than
           shipping two links that 404. -->
      <p class="mt-4 text-center text-[12px] leading-relaxed text-ink-400">
        By proceeding, you agree to our Terms and Privacy Policy.
      </p>
    </div>

    <div class="border-t border-edge-subtle bg-surface-warm px-8 py-4 text-center text-[13px] text-ink-700">
      Already have an account?
      <button
        type="button"
        class="font-medium text-ink-900 underline underline-offset-4 hover:opacity-75"
        @click="goToSignIn"
      >
        Sign in
      </button>
    </div>
  </dialog>
</template>
