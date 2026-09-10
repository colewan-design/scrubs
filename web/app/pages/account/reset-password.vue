<script setup lang="ts">
/**
 * Finish a password reset (§8).
 *
 * The token and email arrive as query parameters on the link emailed by
 * ResetPasswordLink. Landing here without them means the link was mangled in
 * transit, which is worth saying rather than showing a form that cannot work.
 */
const api = useApi()
const route = useRoute()

const token = String(route.query.token || '')
const email = String(route.query.email || '')

const form = reactive({ password: '', password_confirmation: '' })
const loading = ref(false)
const done = ref(false)
const generalError = ref('')
const errors = ref<Record<string, string[]>>({})

const linkIsUsable = computed(() => Boolean(token && email))

async function submit() {
  loading.value = true
  errors.value = {}
  generalError.value = ''

  try {
    await api.post('/auth/reset-password', { token, email, ...form })
    done.value = true
  }
  catch (e: any) {
    if (e?.data?.errors) errors.value = e.data.errors
    else generalError.value = e?.data?.message || 'Something went wrong. Please try again.'
  }
  finally {
    loading.value = false
  }
}

useSeoMeta({ title: 'Choose a new password', robots: 'noindex' })
</script>

<template>
  <div class="container-content pt-10">
    <div class="mx-auto max-w-[420px]">
      <h1 class="font-display text-[32px] text-ink-900">Choose a new password</h1>

      <div v-if="done" class="mt-6 rounded-sm border border-edge-subtle bg-surface-warm p-5">
        <p class="text-[15px] text-ink-900">Your password has been changed.</p>
        <NuxtLink
          to="/account/login"
          class="mt-4 inline-block text-[14px] text-ink-900 underline underline-offset-4"
        >
          Sign in
        </NuxtLink>
      </div>

      <div v-else-if="!linkIsUsable" class="mt-6 rounded-sm border border-edge bg-surface-warm p-5">
        <p class="text-[15px] text-ink-900">This reset link is incomplete.</p>
        <p class="mt-2 text-[14px] text-ink-500">
          Some email clients break long links across lines. Request a new one and open it in a single click.
        </p>
        <NuxtLink
          to="/account/forgot-password"
          class="mt-4 inline-block text-[14px] text-ink-900 underline underline-offset-4"
        >
          Request a new link
        </NuxtLink>
      </div>

      <template v-else>
        <p class="mt-2 text-[15px] text-ink-500">
          Setting a new password for <span class="text-ink-900">{{ email }}</span>.
        </p>

        <form class="mt-7 space-y-4" novalidate @submit.prevent="submit">
          <p
            v-if="generalError"
            class="rounded-sm border border-status-error/30 bg-status-error/5 px-4 py-3 text-[14px] text-status-error"
            role="alert"
          >
            {{ generalError }}
          </p>

          <div>
            <label for="password" class="block text-[13px] font-medium text-ink-900">New password</label>
            <input
              id="password" v-model="form.password" type="password" autocomplete="new-password" required
              class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
            >
            <p v-if="errors.password" class="mt-1 text-[13px] text-status-error">{{ errors.password[0] }}</p>
            <!-- An expired or reused token is reported against the email field. -->
            <p v-if="errors.email" class="mt-1 text-[13px] text-status-error">{{ errors.email[0] }}</p>
          </div>

          <div>
            <label for="password_confirmation" class="block text-[13px] font-medium text-ink-900">
              Confirm new password
            </label>
            <input
              id="password_confirmation" v-model="form.password_confirmation" type="password"
              autocomplete="new-password" required
              class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
            >
          </div>

          <UiBaseButton type="submit" size="lg" block :loading="loading">
            Save new password
          </UiBaseButton>
        </form>
      </template>
    </div>
  </div>
</template>
