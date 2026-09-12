<script setup lang="ts">
import { Check, Lock, MapPin, Package } from 'lucide-vue-next'
import type { Order, SavedAddress } from '~/composables/useApi'

/**
 * The account home (§8): contact information, account status, and the way in to
 * order history and the address book.
 */
definePageMeta({ middleware: 'auth' })

const auth = useAuthStore()
const api = useApi()
const route = useRoute()

/**
 * The email-confirmation link comes back here rather than to a page of its own.
 * There is nothing to do on a dedicated screen except say "thanks" and offer a
 * link back to exactly where this already is.
 */
const verifyOutcomes: Record<string, { message: string; ok: boolean }> = {
  verified: { message: 'Thanks — your email address is confirmed.', ok: true },
  'already-verified': { message: 'That address was already confirmed.', ok: true },
  'link-expired': {
    message: 'That confirmation link has expired. Send yourself a fresh one below.',
    ok: false,
  },
  'link-invalid': {
    message: 'That confirmation link is not valid. Send yourself a fresh one below.',
    ok: false,
  },
}

const verifyNotice = computed(() => verifyOutcomes[String(route.query.verify ?? '')] ?? null)

if (verifyNotice.value?.ok) auth.markVerified()

const { data } = await useAsyncData('account-summary', async () => {
  const [orders, addresses] = await Promise.all([
    api.get<{ data: Order[] }>('/orders'),
    api.get<{ data: SavedAddress[] }>('/account/addresses'),
  ])

  return { orders: orders.data ?? [], addresses: addresses.data ?? [] }
})

const recentOrders = computed(() => (data.value?.orders ?? []).slice(0, 3))
const defaultAddress = computed(
  () => (data.value?.addresses ?? []).find((a) => a.is_default_shipping) ?? null,
)

const contact = computed(() => [
  { label: 'Name', value: auth.user?.name },
  { label: 'Email', value: auth.user?.email },
  { label: 'Phone', value: auth.user?.phone || 'Not provided' },
  { label: 'Business', value: auth.user?.business_name || 'Not provided' },
])

useSeoMeta({ title: 'Your account', robots: 'noindex' })
</script>

<template>
  <AccountShell title="Your account">

    <p
      v-if="verifyNotice"
      class="mt-6 rounded-sm border px-4 py-3 text-[14px]"
      :class="verifyNotice.ok
        ? 'border-sage/30 bg-sage-soft text-ink-900'
        : 'border-edge bg-surface-warm text-ink-700'"
      role="status"
    >
      {{ verifyNotice.message }}
    </p>

    <AccountVerifyEmailNotice class="mt-6" />

    <!-- Suspension is stated plainly rather than left to be discovered at
         checkout. §8 asks for account status; this is what it is for. -->
    <p
      v-if="auth.isSuspended"
      class="mt-6 rounded-sm border border-status-error/30 bg-status-error/5 px-4 py-3 text-[14px] text-status-error"
      role="alert"
    >
      This account is suspended, so orders cannot be placed. Please contact us and we will sort it out.
    </p>

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
      <!-- Contact details -->
      <section class="rounded-sm border border-edge-subtle p-6">
        <div class="flex items-baseline justify-between gap-4">
          <h2 class="text-[15px] font-medium text-ink-900">Contact details</h2>
          <NuxtLink
            to="/account/profile"
            class="text-[13px] text-ink-500 underline underline-offset-4 hover:text-ink-900"
          >
            Edit
          </NuxtLink>
        </div>

        <dl class="mt-4 space-y-2.5 text-[14px]">
          <div v-for="row in contact" :key="row.label" class="flex justify-between gap-4">
            <dt class="text-ink-500">{{ row.label }}</dt>
            <dd class="text-right text-ink-900">{{ row.value }}</dd>
          </div>
          <div v-if="auth.user?.member_since" class="flex justify-between gap-4">
            <dt class="text-ink-500">Member since</dt>
            <dd class="text-ink-900">
              {{ new Date(auth.user.member_since).toLocaleDateString('en-CA', { year: 'numeric', month: 'long' }) }}
            </dd>
          </div>
        </dl>
      </section>

      <!-- Wholesale standing -->
      <section class="rounded-sm border border-sage/25 bg-sage-soft p-6">
        <h2 class="flex items-center gap-2 text-[13px] font-semibold tracking-[0.06em] text-sage uppercase">
          <Lock :size="14" aria-hidden="true" /> Wholesale pricing
        </h2>

        <p class="mt-3 flex items-center gap-2 text-[15px] text-ink-900">
          <Check :size="16" class="text-sage" aria-hidden="true" />
          Unlocked on every product
        </p>

        <p class="mt-3 max-w-[46ch] text-[14px] leading-relaxed text-ink-700">
          Tier discounts apply automatically once an order qualifies — there is no application to
          make and nobody to wait on.
        </p>

        <UiBaseButton to="/wholesale" variant="secondary" size="sm" class="mt-5">
          How the tiers work
        </UiBaseButton>
      </section>

      <!-- Recent orders -->
      <section class="rounded-sm border border-edge-subtle p-6">
        <div class="flex items-baseline justify-between gap-4">
          <h2 class="flex items-center gap-2 text-[15px] font-medium text-ink-900">
            <Package :size="16" class="text-ink-500" aria-hidden="true" /> Recent orders
          </h2>
          <NuxtLink
            v-if="recentOrders.length"
            to="/account/orders"
            class="text-[13px] text-ink-500 underline underline-offset-4 hover:text-ink-900"
          >
            View all
          </NuxtLink>
        </div>

        <p v-if="!recentOrders.length" class="mt-4 text-[14px] text-ink-500">
          You have not placed an order yet.
        </p>

        <ul v-else class="mt-4 divide-y divide-edge-subtle">
          <li v-for="order in recentOrders" :key="order.order_number" class="flex justify-between gap-4 py-2.5">
            <div>
              <NuxtLink
                :to="`/orders/${order.order_number}`"
                class="text-[14px] text-ink-900 underline-offset-4 hover:underline"
              >
                {{ order.order_number }}
              </NuxtLink>
              <p class="text-[13px] text-ink-500">{{ order.status_label }}</p>
            </div>
            <p class="tabular text-[14px] text-ink-900">{{ order.totals.grand_total.formatted }}</p>
          </li>
        </ul>
      </section>

      <!-- Default address -->
      <section class="rounded-sm border border-edge-subtle p-6">
        <div class="flex items-baseline justify-between gap-4">
          <h2 class="flex items-center gap-2 text-[15px] font-medium text-ink-900">
            <MapPin :size="16" class="text-ink-500" aria-hidden="true" /> Default shipping address
          </h2>
          <NuxtLink
            to="/account/addresses"
            class="text-[13px] text-ink-500 underline underline-offset-4 hover:text-ink-900"
          >
            {{ defaultAddress ? 'Manage' : 'Add' }}
          </NuxtLink>
        </div>

        <p v-if="!defaultAddress" class="mt-4 max-w-[46ch] text-[14px] text-ink-500">
          Save an address and checkout fills itself in.
        </p>

        <address v-else class="mt-4 text-[14px] leading-relaxed text-ink-700 not-italic">
          <span class="block text-ink-900">{{ defaultAddress.name }}</span>
          <span v-for="line in defaultAddress.lines" :key="line" class="block">{{ line }}</span>
        </address>
      </section>
    </div>
  </AccountShell>
</template>
