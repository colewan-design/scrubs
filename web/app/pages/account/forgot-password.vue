<script setup lang="ts">
/**
 * Start a password reset (§8).
 *
 * Email delivery is a later phase, so the API can answer 503 with
 * `available: false`. That is not an error to apologise for — it is a real
 * state the customer needs told plainly, with a way forward, rather than a
 * spinner that never resolves.
 */
const api = useApi()

const email = ref('')
const loading = ref(false)
const sent = ref(false)
const unavailable = ref(false)
const message = ref('')
const errors = ref<Record<string, string[]>>({})

async function submit() {
  loading.value = true
  errors.value = {}
  unavailable.value = false
  message.value = ''

  try {
    const res = await api.post<{ message: string, available: boolean }>(
      '/auth/forgot-password',
      { email: email.value },
    )
    sent.value = true
    message.value = res.message
  }
  catch (e: any) {
    if (e?.status === 503 || e?.data?.available === false) {
      unavailable.value = true
      message.value = e?.data?.message || 'Password reset by email is not available yet.'
    }
    else if (e?.data?.errors) {
      errors.value = e.data.errors
    }
    else {
      message.value = e?.data?.message || 'Something went wrong. Please try again.'
    }
  }
  finally {
    loading.value = false
  }
}

useSeoMeta({ title: 'Reset your password', robots: 'noindex' })
</script>

<template>
  <div class="container-content pt-10">
    <div class="mx-auto max-w-[420px]">
      <h1 class="font-display text-[32px] text-ink-900">Reset your password</h1>

      <!-- Sent: no form left to fill in, so it is replaced rather than annotated. -->
      <div v-if="sent" class="mt-6 rounded-sm border border-edge-subtle bg-surface-warm p-5">
        <p class="text-[15px] text-ink-900">{{ message }}</p>
        <p class="mt-2 text-[14px] text-ink-500">
          The link expires in an hour. Check your spam folder if it has not arrived in a few minutes.
        </p>
        <NuxtLink
          to="/account/login"
          class="mt-4 inline-block text-[14px] text-ink-900 underline underline-offset-4"
        >
          Back to sign in
        </NuxtLink>
      </div>

      <div v-else-if="unavailable" class="mt-6 rounded-sm border border-edge bg-surface-warm p-5">
        <p class="text-[15px] text-ink-900">{{ message }}</p>
        <NuxtLink
          to="/contact"
          class="mt-4 inline-block text-[14px] text-ink-900 underline underline-offset-4"
        >
          Contact us
        </NuxtLink>
      </div>

      <template v-else>
        <p class="mt-2 text-[15px] text-ink-500">
          Enter the email you signed up with and we will send you a link to choose a new password.
        </p>

        <form class="mt-7 space-y-4" novalidate @submit.prevent="submit">
          <p
            v-if="message"
            class="rounded-sm border border-status-error/30 bg-status-error/5 px-4 py-3 text-[14px] text-status-error"
            role="alert"
          >
            {{ message }}
          </p>

          <div>
            <label for="email" class="block text-[13px] font-medium text-ink-900">Email</label>
            <input
              id="email" v-model="email" type="email" autocomplete="email" required
              class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
            >
            <p v-if="errors.email" class="mt-1 text-[13px] text-status-error">{{ errors.email[0] }}</p>
          </div>

          <UiBaseButton type="submit" size="lg" block :loading="loading">
            Send reset link
          </UiBaseButton>

          <p class="text-center text-[13px] text-ink-500">
            <NuxtLink to="/account/login" class="text-ink-900 underline underline-offset-4">
              Back to sign in
            </NuxtLink>
          </p>
        </form>
      </template>
    </div>
  </div>
</template>
