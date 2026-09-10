<script setup lang="ts">
const auth = useAuthStore()
const route = useRoute()

const form = reactive({
  // Carried from the unlock dialog, so the address is typed exactly once.
  email: typeof route.query.email === 'string' ? route.query.email : '',
  password: '',
  remember: true,
})
const errors = ref<Record<string, string[]>>({})

// OAuth ends in a browser redirect back here, so a failed sign-in arrives as a
// query string rather than a rejected promise.
const generalError = ref(String(route.query.message || ''))

async function submit() {
  errors.value = {}
  generalError.value = ''
  try {
    await auth.login({ ...form })
    await navigateTo(String(route.query.redirect || '/account'))
  } catch (e: any) {
    if (e?.data?.errors) errors.value = e.data.errors
    else generalError.value = e?.data?.message || 'Something went wrong. Please try again.'
  }
}

useSeoMeta({ title: 'Sign in', robots: 'noindex' })
</script>

<template>
  <div class="container-content pt-10">
    <div class="mx-auto max-w-[420px]">
      <h1 class="font-display text-[32px] text-ink-900">Sign in</h1>
      <p class="mt-2 text-[15px] text-ink-500">
        Sign in to see wholesale pricing and your orders.
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
          <label for="email" class="block text-[13px] font-medium text-ink-900">Email</label>
          <input
            id="email" v-model="form.email" type="email" autocomplete="email" required
            class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
          >
          <p v-if="errors.email" class="mt-1 text-[13px] text-status-error">{{ errors.email[0] }}</p>
        </div>

        <div>
          <label for="password" class="block text-[13px] font-medium text-ink-900">Password</label>
          <input
            id="password" v-model="form.password" type="password" autocomplete="current-password" required
            class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
          >
        </div>

        <div class="flex items-center justify-between gap-4">
          <label class="flex cursor-pointer items-center gap-2 py-1 text-[13px] text-ink-700">
            <input v-model="form.remember" type="checkbox" class="size-4 rounded-sm border-edge accent-ink-900">
            Keep me signed in
          </label>

          <NuxtLink
            to="/account/forgot-password"
            class="text-[13px] text-ink-500 underline underline-offset-4 hover:text-ink-900"
          >
            Forgot password?
          </NuxtLink>
        </div>

        <UiBaseButton type="submit" size="lg" block :loading="auth.loading">Sign in</UiBaseButton>

        <AuthSocialButtons class="pt-1" />

        <p class="text-center text-[13px] text-ink-500">
          New here?
          <NuxtLink to="/account/register" class="text-ink-900 underline underline-offset-4">
            Create an account
          </NuxtLink>
        </p>
      </form>
    </div>
  </div>
</template>
