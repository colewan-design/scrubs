<script setup lang="ts">
import { MailCheck } from 'lucide-vue-next'

/**
 * The "confirm your email" prompt (§8).
 *
 * Deliberately a notice, not a gate. Wholesale eligibility is set by order
 * size, so an unconfirmed address blocks nothing — what it costs the customer
 * is order confirmations and tracking, which is exactly what this says.
 *
 * It also has to cope with email being switched off entirely (§10). Promising
 * that a link is "on its way" when the mailer is not configured would leave
 * someone refreshing an inbox forever, so the API reports whether it actually
 * sent and this repeats that answer rather than assuming.
 */
const auth = useAuthStore()

const sending = ref(false)
const message = ref('')
const failed = ref(false)

async function resend() {
  sending.value = true
  message.value = ''
  failed.value = false
  try {
    const res = await auth.resendVerification()
    message.value = res.message
    failed.value = !res.sent
  } catch (e: any) {
    message.value = e?.data?.message || 'We could not send that just now. Please try again shortly.'
    failed.value = true
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div
    v-if="auth.needsEmailVerification"
    class="rounded-sm border border-edge bg-surface-warm px-5 py-4"
  >
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="flex gap-3">
        <MailCheck :size="18" class="mt-0.5 shrink-0 text-ink-500" aria-hidden="true" />
        <div>
          <p class="text-[14px] font-medium text-ink-900">Confirm your email address</p>
          <p class="mt-1 max-w-[58ch] text-[13px] leading-relaxed text-ink-500">
            Your account is fully active and wholesale pricing is already unlocked. Confirming
            <span class="text-ink-700">{{ auth.user?.email }}</span> is what lets us send order
            confirmations and tracking.
          </p>
        </div>
      </div>

      <UiBaseButton variant="secondary" size="sm" :loading="sending" @click="resend">
        Resend link
      </UiBaseButton>
    </div>

    <p
      v-if="message"
      class="mt-3 text-[13px]"
      :class="failed ? 'text-ink-500' : 'text-sage'"
      role="status"
    >
      {{ message }}
    </p>
  </div>
</template>
