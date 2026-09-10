<script setup lang="ts">
import { Lock } from 'lucide-vue-next'

/**
 * §3: "Keep initial registration simple… The priority is a fast, low-friction
 * signup." Everything on one screen, only four required fields, and the
 * wholesale benefit stated right beside the form so the reason to sign up is
 * visible while filling it in.
 */
const auth = useAuthStore()
const cart = useCartStore()
const route = useRoute()

const form = reactive({
  name: '',
  // Carried from the unlock dialog, so the address is typed exactly once.
  email: typeof route.query.email === 'string' ? route.query.email : '',
  phone: '',
  password: '',
  password_confirmation: '',
  business_name: '',
  city: '',
  province: '',
})

const errors = ref<Record<string, string[]>>({})
const generalError = ref('')

const provinces = ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT']

async function submit() {
  errors.value = {}
  generalError.value = ''
  try {
    await auth.register({ ...form })
    await navigateTo('/account')
  } catch (e: any) {
    if (e?.data?.errors) errors.value = e.data.errors
    else generalError.value = e?.data?.message || 'Something went wrong. Please try again.'
  }
}

useSeoMeta({ title: 'Create an account', robots: 'noindex' })
</script>

<template>
  <div class="container-content pt-10">
    <div class="grid gap-12 lg:grid-cols-[1fr_380px]">
      <!-- Form -->
      <div class="max-w-[520px]">
        <h1 class="font-display text-[32px] text-ink-900">Create your account</h1>
        <p class="mt-2 text-[15px] text-ink-500">
          Free to join. No business registration required.
        </p>

        <form class="mt-7 space-y-4" novalidate @submit.prevent="submit">
          <p
            v-if="generalError"
            class="rounded-sm border border-status-error/30 bg-status-error/5 px-4 py-3 text-[14px] text-status-error"
            role="alert"
          >
            {{ generalError }}
          </p>

          <!-- Labels above the field, never placeholder-as-label. -->
          <div>
            <label for="name" class="block text-[13px] font-medium text-ink-900">Full name</label>
            <input
              id="name" v-model="form.name" type="text" autocomplete="name" required
              class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
            >
            <p v-if="errors.name" class="mt-1 text-[13px] text-status-error">{{ errors.name[0] }}</p>
          </div>

          <div>
            <label for="email" class="block text-[13px] font-medium text-ink-900">Email</label>
            <input
              id="email" v-model="form.email" type="email" autocomplete="email" required
              class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
            >
            <p v-if="errors.email" class="mt-1 text-[13px] text-status-error">{{ errors.email[0] }}</p>
          </div>

          <div>
            <label for="phone" class="block text-[13px] font-medium text-ink-900">Phone</label>
            <input
              id="phone" v-model="form.phone" type="tel" autocomplete="tel" required
              class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
            >
            <p v-if="errors.phone" class="mt-1 text-[13px] text-status-error">{{ errors.phone[0] }}</p>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label for="password" class="block text-[13px] font-medium text-ink-900">Password</label>
              <input
                id="password" v-model="form.password" type="password" autocomplete="new-password" required
                class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
              >
              <p v-if="errors.password" class="mt-1 text-[13px] text-status-error">{{ errors.password[0] }}</p>
            </div>
            <div>
              <label for="password_confirmation" class="block text-[13px] font-medium text-ink-900">
                Confirm password
              </label>
              <input
                id="password_confirmation" v-model="form.password_confirmation" type="password"
                autocomplete="new-password" required
                class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
              >
            </div>
          </div>

          <details class="pt-1">
            <summary class="cursor-pointer text-[13px] text-ink-500 hover:text-ink-900">
              Add business details (optional)
            </summary>
            <div class="mt-4 space-y-4">
              <div>
                <label for="business_name" class="block text-[13px] font-medium text-ink-900">
                  Business name
                </label>
                <input
                  id="business_name" v-model="form.business_name" type="text" autocomplete="organization"
                  class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
                >
              </div>
              <div class="grid gap-4 sm:grid-cols-2">
                <div>
                  <label for="city" class="block text-[13px] font-medium text-ink-900">City</label>
                  <input
                    id="city" v-model="form.city" type="text" autocomplete="address-level2"
                    class="mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] focus:border-edge-strong focus:outline-none"
                  >
                </div>
                <div>
                  <label for="province" class="block text-[13px] font-medium text-ink-900">Province</label>
                  <select
                    id="province" v-model="form.province"
                    class="mt-1.5 h-11 w-full rounded-sm border border-edge bg-white px-3 text-[15px] focus:border-edge-strong focus:outline-none"
                  >
                    <option value="">Select</option>
                    <option v-for="p in provinces" :key="p" :value="p">{{ p }}</option>
                  </select>
                </div>
              </div>
            </div>
          </details>

          <UiBaseButton type="submit" size="lg" block :loading="auth.loading">
            Create account
          </UiBaseButton>

          <AuthSocialButtons verb="Sign up with" />

          <p class="text-center text-[13px] text-ink-500">
            Already have an account?
            <NuxtLink to="/account/login" class="text-ink-900 underline underline-offset-4">Sign in</NuxtLink>
          </p>
        </form>
      </div>

      <!-- The reason to sign up, visible while filling in the form. -->
      <aside class="lg:pt-16">
        <div class="rounded-sm border border-sage/25 bg-sage-soft p-6">
          <p class="flex items-center gap-2 text-[13px] font-semibold tracking-[0.06em] text-sage uppercase">
            <Lock :size="14" aria-hidden="true" /> What you unlock
          </p>
          <ul class="mt-4 space-y-3 text-[14px] text-ink-700">
            <li>Wholesale pricing on every product, visible as soon as you sign in.</li>
            <li>Automatic tier discounts once your order reaches $200 — no application.</li>
            <li>Free shipping on qualifying orders over $600.</li>
            <li>Order history and tracking in one place.</li>
          </ul>

          <div
            v-if="cart.unlockPrompt?.qualifies"
            class="mt-5 border-t border-sage/20 pt-4"
          >
            <p class="text-[14px] text-ink-900">
              Your current cart qualifies — you will save
              <span class="tabular font-semibold text-sage">
                {{ cart.unlockPrompt.saving?.formatted }}
              </span>
              as soon as you finish signing up.
            </p>
          </div>
        </div>
      </aside>
    </div>
  </div>
</template>
