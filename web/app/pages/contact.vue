<script setup lang="ts">
import { Mail, MapPin, Phone, Store } from 'lucide-vue-next'

/**
 * Contact details (§1).
 *
 * Everything here is admin-editable settings — business email, phone, address
 * and pickup details are all outstanding client material, and hardcoding them
 * would mean a deployment to correct a phone number.
 */
const api = useApi()

interface StoreContent {
  email: string | null
  phone: string | null
  address: string | null
  hours: string | null
  social: Record<string, string>
  pickup: { address: string | null; hours: string | null; lead_time: string | null } | null
}

const { data } = await useAsyncData('store-contact', () => api.get<StoreContent>('/content/store'))

const store = computed(() => data.value)
const hasAnyDetail = computed(() =>
  Boolean(store.value?.email || store.value?.phone || store.value?.address),
)

useSeoMeta({
  title: 'Contact',
  description: 'Get in touch with BulkScrubsDirect about orders, wholesale pricing or returns.',
})
</script>

<template>
  <div class="container-content pt-10 pb-20">
    <div class="max-w-[68ch]">
      <h1 class="font-display text-[36px] leading-tight text-ink-900">Contact us</h1>
      <p class="mt-3 text-[16px] leading-relaxed text-ink-700">
        Questions about an order, wholesale pricing or a return — we would rather
        you asked than guessed.
      </p>

      <dl v-if="hasAnyDetail" class="mt-8 space-y-5">
        <div v-if="store?.email" class="flex gap-3">
          <Mail :size="18" class="mt-0.5 shrink-0 text-ink-400" aria-hidden="true" />
          <div>
            <dt class="text-[12px] tracking-[0.06em] text-ink-500 uppercase">Email</dt>
            <dd class="mt-0.5 text-[15px]">
              <a :href="`mailto:${store.email}`" class="text-ink-900 underline underline-offset-4 hover:opacity-75">
                {{ store.email }}
              </a>
            </dd>
          </div>
        </div>

        <div v-if="store?.phone" class="flex gap-3">
          <Phone :size="18" class="mt-0.5 shrink-0 text-ink-400" aria-hidden="true" />
          <div>
            <dt class="text-[12px] tracking-[0.06em] text-ink-500 uppercase">Phone</dt>
            <dd class="mt-0.5 text-[15px]">
              <a :href="`tel:${store.phone.replace(/\s/g, '')}`" class="text-ink-900 underline underline-offset-4 hover:opacity-75">
                {{ store.phone }}
              </a>
              <span v-if="store.hours" class="block text-[13px] text-ink-500">{{ store.hours }}</span>
            </dd>
          </div>
        </div>

        <div v-if="store?.address" class="flex gap-3">
          <MapPin :size="18" class="mt-0.5 shrink-0 text-ink-400" aria-hidden="true" />
          <div>
            <dt class="text-[12px] tracking-[0.06em] text-ink-500 uppercase">Address</dt>
            <dd class="mt-0.5 text-[15px] whitespace-pre-line text-ink-900">{{ store.address }}</dd>
          </div>
        </div>
      </dl>

      <p v-else class="mt-8 rounded-sm border border-edge-subtle bg-surface-warm p-5 text-[14px] text-ink-700">
        Our contact details are being finalised and will appear here shortly.
      </p>

      <section v-if="store?.pickup?.address" class="mt-10 border-t border-edge-subtle pt-8">
        <h2 class="flex items-center gap-2 font-display text-[22px] text-ink-900">
          <Store :size="18" class="text-ink-400" aria-hidden="true" /> Local pickup
        </h2>
        <p class="mt-2 text-[15px] whitespace-pre-line text-ink-700">{{ store.pickup.address }}</p>
        <p v-if="store.pickup.hours" class="mt-1 text-[14px] text-ink-500">{{ store.pickup.hours }}</p>
        <p v-if="store.pickup.lead_time" class="mt-1 text-[14px] text-ink-500">
          {{ store.pickup.lead_time }}
        </p>
      </section>
    </div>
  </div>
</template>
