<script setup lang="ts">
/**
 * The PayPal button (§4).
 *
 * This component owns the PayPal SDK and nothing else. It does not know what an
 * order costs, and it never tells PayPal — it asks the parent for a PayPal order
 * id (which Laravel created from the persisted order total) and hands the
 * approval back. Every figure stays server-side, which is the whole point.
 *
 * The SDK is loaded on demand rather than in nuxt.config: it is a third-party
 * script that sets cookies, and a shopper who never reaches checkout should not
 * be made to download it.
 */
const props = defineProps<{
  clientId: string
  currency?: string
  /** Blocks the button while the form is incomplete or something is in flight. */
  disabled?: boolean
  /** Places the order server-side and resolves with PayPal's order id. */
  createOrder: () => Promise<string>
  /** Captures it. Resolves once our order is confirmed. */
  onApproved: (paypalOrderId: string) => Promise<void>
}>()

const emit = defineEmits<{
  cancel: []
  error: [message: string]
}>()

const host = ref<HTMLElement | null>(null)
const loading = ref(true)
const failed = ref(false)

/**
 * Nothing here is reactive to `disabled` — the SDK renders an iframe and
 * re-rendering it on every keystroke would be both slow and visibly flickery.
 * The guard lives in createOrder instead, with the wrapper below making it
 * obvious that the button is not currently usable.
 */
let instance: { close: () => void } | null = null

/** Loads the SDK once per page, even if this component mounts more than once. */
function loadSdk(clientId: string, currency: string): Promise<any> {
  const w = window as any

  if (w.paypal) return Promise.resolve(w.paypal)
  if (w.__bsdPayPalSdk) return w.__bsdPayPalSdk

  w.__bsdPayPalSdk = new Promise((resolve, reject) => {
    const params = new URLSearchParams({
      'client-id': clientId,
      currency,
      intent: 'capture',
      components: 'buttons',
      locale: 'en_CA',
      // Card is deliberately excluded: taking a card through PayPal's
      // hosted-fields flow is a separate integration with its own PCI scope,
      // and offering a card button that only leads to a PayPal login is a
      // worse experience than not offering one.
      'disable-funding': 'card',
    })

    const script = document.createElement('script')
    script.src = `https://www.paypal.com/sdk/js?${params.toString()}`
    script.async = true
    script.onload = () => (w.paypal ? resolve(w.paypal) : reject(new Error('SDK loaded without paypal global')))
    script.onerror = () => {
      // Cleared so a later mount can retry — a blocked script on one navigation
      // must not permanently disable PayPal for the session.
      delete w.__bsdPayPalSdk
      reject(new Error('SDK failed to load'))
    }
    document.head.appendChild(script)
  })

  return w.__bsdPayPalSdk
}

onMounted(async () => {
  let paypal: any

  try {
    paypal = await loadSdk(props.clientId, props.currency || 'CAD')
  } catch {
    loading.value = false
    failed.value = true

    return
  }

  if (!host.value) return

  try {
    instance = paypal.Buttons({
      style: { layout: 'vertical', shape: 'rect', color: 'gold', label: 'paypal', height: 48 },

      createOrder: async () => {
        // The last line of defence against paying for an order that is not
        // ready. PayPal shows its own generic error when this rejects, which is
        // why the parent also keeps the button visually disabled.
        if (props.disabled) throw new Error('Checkout is not ready.')

        return await props.createOrder()
      },

      onApprove: async (data: { orderID: string }) => {
        await props.onApproved(data.orderID)
      },

      // The customer closed the PayPal window. Not an error: their order exists
      // as Pending Payment and they can try again.
      onCancel: () => emit('cancel'),

      onError: (err: unknown) => {
        // The SDK funnels everything through here, including our own thrown
        // errors from createOrder. The parent already has the real message in
        // that case, so this only reports what it can be sure about.
        console.error('[paypal]', err)
        emit('error', '')
      },
    })

    await instance.render(host.value)
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  try {
    instance?.close()
  } catch {
    // Closing a button that never rendered throws; there is nothing to do
    // about it and nothing depends on it.
  }
})
</script>

<template>
  <div>
    <div
      v-if="loading"
      class="h-12 animate-pulse rounded-sm bg-surface-warm-deep"
      aria-hidden="true"
    />

    <p v-if="failed" class="text-[13px] text-status-error" role="alert">
      PayPal could not be loaded. Check your connection or any ad blocker, or choose another
      payment method.
    </p>

    <!-- Kept mounted whatever happens: the SDK renders into this element, and
         tearing it out on a transient state change loses the button. -->
    <div
      v-show="!failed"
      ref="host"
      :class="disabled && 'pointer-events-none opacity-50'"
      :aria-disabled="disabled || undefined"
    />

    <p v-if="disabled && !failed && !loading" class="mt-2 text-[13px] text-ink-500">
      Complete the details above to pay with PayPal.
    </p>
  </div>
</template>
