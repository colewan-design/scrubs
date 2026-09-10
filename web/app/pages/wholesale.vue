<script setup lang="ts">
import { Lock, Check } from 'lucide-vue-next'

/**
 * §11's "Wholesale / How It Works" page.
 *
 * This is the page that converts a browser into an account, so it gets real
 * design attention rather than being treated as filler. The ladder renders from
 * live tier data — never hardcoded copy that could drift from what the cart does.
 */
const api = useApi()
const auth = useAuthStore()

const { data } = await useAsyncData('wholesale-page', () => api.get<any>('/wholesale'))

useSeoMeta({
  title: 'Wholesale Pricing',
  description:
    'Unlock wholesale scrub pricing with a free account. No application, no approval — your order size sets your price.',
})

const steps = [
  { title: 'Create a free account', body: 'Takes about a minute. No business registration, no application, no waiting for approval.' },
  { title: 'Add what you need', body: 'Browse the full catalogue. Wholesale prices appear as soon as you are signed in.' },
  { title: 'Reach a threshold', body: 'The cart works out which tier you have reached and applies it automatically.' },
  { title: 'Checkout', body: 'Shipping and Canadian taxes are calculated at checkout. Free shipping over $600.' },
]
</script>

<template>
  <div>
    <section class="container-content pt-10">
      <div class="max-w-[62ch]">
        <p class="text-[11px] font-semibold tracking-[0.1em] text-sage uppercase">Wholesale</p>
        <h1 class="mt-3 font-display text-[42px] leading-[1.1] text-ink-900">
          Your order size sets your price
        </h1>
        <p class="mt-4 text-[17px] leading-relaxed text-ink-700">
          There is no application to fill in and nobody to wait on. Create a free
          account to see wholesale pricing, and the cart applies the right tier
          automatically once your order qualifies.
        </p>
        <div v-if="!auth.isAuthenticated" class="mt-6 flex flex-wrap gap-2">
          <UiBaseButton to="/account/register" size="lg">Create a free account</UiBaseButton>
          <UiBaseButton to="/account/login" variant="secondary" size="lg">Sign in</UiBaseButton>
        </div>
      </div>
    </section>

    <!-- The tier ladder -->
    <section class="container-content pt-16">
      <h2 class="font-display text-[28px] text-ink-900">Pricing tiers</h2>
      <p class="mt-1.5 text-[15px] text-ink-500">
        Qualify by order value or by units — whichever you reach first.
      </p>

      <!-- Each rung prices EITHER as an absolute per-set figure or as a
           percentage off retail, depending on the tier's discount type.
           Handling only the first case left signed-in members looking at the
           locked state on every rung. -->
      <div class="mt-6 space-y-3">
        <div
          v-for="tier in data?.tiers ?? []"
          :key="tier.slug"
          class="flex flex-wrap items-center gap-x-8 gap-y-3 rounded-sm border border-edge-subtle bg-surface-warm p-5"
        >
          <div class="min-w-[120px]">
            <p class="text-[11px] font-semibold tracking-[0.08em] text-ink-500 uppercase">
              {{ tier.name }}
            </p>
          </div>

          <div class="min-w-[200px] flex-1">
            <p class="text-[15px] text-ink-900">
              From <span class="tabular font-medium">{{ tier.min_subtotal.formatted }}</span>
              <template v-if="tier.min_qty">
                <span class="text-ink-500"> or </span>
                <span class="font-medium">{{ tier.min_qty }} units</span>
              </template>
            </p>
          </div>

          <div class="text-right">
            <p v-if="tier.locked" class="inline-flex items-center gap-2 text-[15px] text-sage">
              <Lock :size="15" aria-hidden="true" /> Sign in to view
            </p>
            <template v-else-if="tier.unit_price">
              <p class="tabular font-display text-[24px] text-ink-900">
                {{ tier.unit_price.formatted }}
              </p>
              <p class="text-[12px] text-ink-500">per set</p>
            </template>
            <template v-else-if="tier.discount_percent">
              <p class="tabular font-display text-[24px] text-ink-900">
                {{ tier.discount_percent }}% off
              </p>
              <p class="text-[12px] text-ink-500">every item</p>
            </template>
          </div>
        </div>
      </div>

      <p class="mt-4 text-[13px] text-ink-500">
        Minimum wholesale order {{ data?.min_order?.formatted }}. Below that, you
        can still buy at regular retail pricing.
      </p>
    </section>

    <!-- How it works -->
    <section class="container-content pt-16">
      <h2 class="font-display text-[28px] text-ink-900">How it works</h2>
      <ol class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <li
          v-for="(step, i) in steps"
          :key="step.title"
          class="border-t border-edge pt-4"
        >
          <span class="tabular text-[12px] font-semibold text-sage">
            {{ String(i + 1).padStart(2, '0') }}
          </span>
          <h3 class="mt-2 font-body text-[15px] font-medium text-ink-900">{{ step.title }}</h3>
          <p class="mt-1.5 text-[14px] leading-relaxed text-ink-500">{{ step.body }}</p>
        </li>
      </ol>
    </section>

    <section class="container-content pt-16">
      <div class="rounded-sm border border-edge-subtle bg-surface-warm p-8">
        <h2 class="font-display text-[24px] text-ink-900">Common questions</h2>
        <dl class="mt-5 grid gap-6 md:grid-cols-2">
          <div>
            <dt class="text-[15px] font-medium text-ink-900">
              Do I need a registered business?
            </dt>
            <dd class="mt-1.5 flex gap-2 text-[14px] text-ink-700">
              <Check :size="16" class="mt-0.5 shrink-0 text-sage" aria-hidden="true" />
              No. Your order size is the only qualification.
            </dd>
          </div>
          <div>
            <dt class="text-[15px] font-medium text-ink-900">Is there an approval process?</dt>
            <dd class="mt-1.5 flex gap-2 text-[14px] text-ink-700">
              <Check :size="16" class="mt-0.5 shrink-0 text-sage" aria-hidden="true" />
              None. Pricing unlocks the moment you create an account.
            </dd>
          </div>
          <div>
            <dt class="text-[15px] font-medium text-ink-900">
              What if my order is under the minimum?
            </dt>
            <dd class="mt-1.5 flex gap-2 text-[14px] text-ink-700">
              <Check :size="16" class="mt-0.5 shrink-0 text-sage" aria-hidden="true" />
              You can still order at regular retail pricing.
            </dd>
          </div>
          <div>
            <dt class="text-[15px] font-medium text-ink-900">When is shipping free?</dt>
            <dd class="mt-1.5 flex gap-2 text-[14px] text-ink-700">
              <Check :size="16" class="mt-0.5 shrink-0 text-sage" aria-hidden="true" />
              On qualifying orders over {{ data?.free_shipping_threshold?.formatted }}.
            </dd>
          </div>
        </dl>
      </div>
    </section>
  </div>
</template>
