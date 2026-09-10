<script setup lang="ts">
/**
 * Contact details and password (§8).
 *
 * Two independent forms on one page, each saving on its own. Combining them
 * would mean a customer changing their phone number has to think about their
 * password, and a failed password confirmation would discard the phone edit.
 */
definePageMeta({ middleware: 'auth' })

const auth = useAuthStore()

const provinces = ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT']

const inputClass =
  'mt-1.5 h-11 w-full rounded-sm border border-edge px-3.5 text-[15px] ' +
  'focus:border-edge-strong focus:outline-none'

// ---------------------------------------------------------------- contact

const profile = reactive({
  name: auth.user?.name ?? '',
  email: auth.user?.email ?? '',
  phone: auth.user?.phone ?? '',
  business_name: auth.user?.business_name ?? '',
  city: auth.user?.city ?? '',
  province: auth.user?.province ?? '',
})

const profileErrors = ref<Record<string, string[]>>({})
const profileNotice = ref('')
const profileSaving = ref(false)

async function saveProfile() {
  profileErrors.value = {}
  profileNotice.value = ''
  profileSaving.value = true

  try {
    const res = await auth.updateProfile({ ...profile })

    // Changing the address un-verifies it, so say so rather than letting the
    // confirmation banner reappear unexplained.
    profileNotice.value = res.email_changed
      ? res.verification_sent
        ? 'Saved. We have sent a confirmation link to your new address.'
        : 'Saved. Your new address is unconfirmed — we will confirm it once order emails are switched on.'
      : 'Your details have been saved.'
  } catch (e: any) {
    if (e?.data?.errors) profileErrors.value = e.data.errors
    else profileNotice.value = e?.data?.message || 'Something went wrong. Please try again.'
  } finally {
    profileSaving.value = false
  }
}

// --------------------------------------------------------------- password

/** A Google-created account has no password yet, so there is none to confirm. */
const hasPassword = computed(() => auth.user?.has_password !== false)

const password = reactive({ current_password: '', password: '', password_confirmation: '' })
const passwordErrors = ref<Record<string, string[]>>({})
const passwordNotice = ref('')
const passwordSaving = ref(false)

async function savePassword() {
  passwordErrors.value = {}
  passwordNotice.value = ''
  passwordSaving.value = true

  try {
    const res = await auth.updatePassword({
      ...(hasPassword.value ? { current_password: password.current_password } : {}),
      password: password.password,
      password_confirmation: password.password_confirmation,
    })

    passwordNotice.value = res.message
    password.current_password = ''
    password.password = ''
    password.password_confirmation = ''
  } catch (e: any) {
    if (e?.data?.errors) passwordErrors.value = e.data.errors
    else passwordNotice.value = e?.data?.message || 'Something went wrong. Please try again.'
  } finally {
    passwordSaving.value = false
  }
}

useSeoMeta({ title: 'Your profile', robots: 'noindex' })
</script>

<template>
  <div class="container-content pt-10 pb-20">
    <h1 class="font-display text-[32px] text-ink-900">Your profile</h1>

    <AccountNav class="mt-6" />

    <div class="mt-8 max-w-[560px] space-y-10">
      <!-- Contact details -->
      <section>
        <h2 class="text-[15px] font-medium text-ink-900">Contact details</h2>
        <p class="mt-1 text-[13px] text-ink-500">
          We use these to reach you about an order. Business details stay optional — wholesale
          pricing is set by order size, not by them.
        </p>

        <form class="mt-5 space-y-4" novalidate @submit.prevent="saveProfile">
          <p
            v-if="profileNotice"
            class="rounded-sm border border-sage/30 bg-sage-soft px-4 py-3 text-[14px] text-ink-900"
            role="status"
          >
            {{ profileNotice }}
          </p>

          <div>
            <label for="name" class="block text-[13px] font-medium text-ink-900">Full name</label>
            <input id="name" v-model="profile.name" type="text" autocomplete="name" :class="inputClass">
            <p v-if="profileErrors.name" class="mt-1 text-[13px] text-status-error">
              {{ profileErrors.name[0] }}
            </p>
          </div>

          <div>
            <label for="email" class="block text-[13px] font-medium text-ink-900">Email</label>
            <input id="email" v-model="profile.email" type="email" autocomplete="email" :class="inputClass">
            <p class="mt-1 text-[12px] text-ink-400">
              Changing this means confirming the new address before we send order emails to it.
            </p>
            <p v-if="profileErrors.email" class="mt-1 text-[13px] text-status-error">
              {{ profileErrors.email[0] }}
            </p>
          </div>

          <div>
            <label for="phone" class="block text-[13px] font-medium text-ink-900">Phone</label>
            <input id="phone" v-model="profile.phone" type="tel" autocomplete="tel" :class="inputClass">
            <p v-if="profileErrors.phone" class="mt-1 text-[13px] text-status-error">
              {{ profileErrors.phone[0] }}
            </p>
          </div>

          <div>
            <label for="business_name" class="block text-[13px] font-medium text-ink-900">
              Business name <span class="font-normal text-ink-400">(optional)</span>
            </label>
            <input
              id="business_name" v-model="profile.business_name" type="text"
              autocomplete="organization" :class="inputClass"
            >
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label for="city" class="block text-[13px] font-medium text-ink-900">
                City <span class="font-normal text-ink-400">(optional)</span>
              </label>
              <input
                id="city" v-model="profile.city" type="text"
                autocomplete="address-level2" :class="inputClass"
              >
            </div>
            <div>
              <label for="province" class="block text-[13px] font-medium text-ink-900">
                Province <span class="font-normal text-ink-400">(optional)</span>
              </label>
              <select
                id="province" v-model="profile.province"
                class="mt-1.5 h-11 w-full rounded-sm border border-edge bg-white px-3 text-[15px] focus:border-edge-strong focus:outline-none"
              >
                <option value="">Select</option>
                <option v-for="p in provinces" :key="p" :value="p">{{ p }}</option>
              </select>
            </div>
          </div>

          <UiBaseButton type="submit" :loading="profileSaving">Save details</UiBaseButton>
        </form>
      </section>

      <!-- Password -->
      <section class="border-t border-edge-subtle pt-10">
        <h2 class="text-[15px] font-medium text-ink-900">
          {{ hasPassword ? 'Change your password' : 'Set a password' }}
        </h2>
        <p v-if="!hasPassword" class="mt-1 max-w-[52ch] text-[13px] text-ink-500">
          You signed up with
          {{ auth.user?.auth_providers.map((p) => p[0]!.toUpperCase() + p.slice(1)).join(' and ') || 'a social account' }},
          so there is no password on this account yet. Setting one lets you sign in either way.
        </p>

        <form class="mt-5 space-y-4" novalidate @submit.prevent="savePassword">
          <p
            v-if="passwordNotice"
            class="rounded-sm border border-sage/30 bg-sage-soft px-4 py-3 text-[14px] text-ink-900"
            role="status"
          >
            {{ passwordNotice }}
          </p>

          <div v-if="hasPassword">
            <label for="current_password" class="block text-[13px] font-medium text-ink-900">
              Current password
            </label>
            <input
              id="current_password" v-model="password.current_password" type="password"
              autocomplete="current-password" :class="inputClass"
            >
            <p v-if="passwordErrors.current_password" class="mt-1 text-[13px] text-status-error">
              {{ passwordErrors.current_password[0] }}
            </p>
          </div>

          <div>
            <label for="new_password" class="block text-[13px] font-medium text-ink-900">
              New password
            </label>
            <input
              id="new_password" v-model="password.password" type="password"
              autocomplete="new-password" :class="inputClass"
            >
            <p v-if="passwordErrors.password" class="mt-1 text-[13px] text-status-error">
              {{ passwordErrors.password[0] }}
            </p>
          </div>

          <div>
            <label for="password_confirmation" class="block text-[13px] font-medium text-ink-900">
              Confirm new password
            </label>
            <input
              id="password_confirmation" v-model="password.password_confirmation" type="password"
              autocomplete="new-password" :class="inputClass"
            >
          </div>

          <UiBaseButton type="submit" :loading="passwordSaving">
            {{ hasPassword ? 'Change password' : 'Set password' }}
          </UiBaseButton>
        </form>
      </section>
    </div>
  </div>
</template>
