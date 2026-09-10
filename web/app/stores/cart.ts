import { defineStore } from 'pinia'
import type { CartItem, CartQuote, CartResponse } from '~/composables/useApi'

/**
 * Cart state mirrors the server's quote exactly.
 *
 * This store performs NO arithmetic on money. Every figure it exposes was
 * calculated by PricingService and sent over the wire — that is what keeps the
 * displayed price and the charged price from ever diverging.
 */
export const useCartStore = defineStore('cart', () => {
  // Created during store setup. Calling useCookie() inside apply() would run it
  // after an await, where the Nuxt instance is no longer on the async context.
  const cartToken = useCookie<string | null>('bsd_cart', {
    maxAge: 60 * 60 * 24 * 30,
    sameSite: 'lax',
  })

  const items = ref<CartItem[]>([])
  const quote = ref<CartQuote | null>(null)
  const itemCount = ref(0)
  const loading = ref(false)
  const drawerOpen = ref(false)

  const isEmpty = computed(() => items.value.length === 0)
  const tier = computed(() => quote.value?.tier ?? null)
  const nextTier = computed(() => quote.value?.next_tier ?? null)
  const unlockPrompt = computed(() => quote.value?.unlock_prompt ?? null)
  const freeShipping = computed(() => quote.value?.free_shipping ?? null)

  function apply(res: CartResponse) {
    items.value = res.items
    quote.value = res.quote
    itemCount.value = res.item_count

    if (res.cart_token) {
      cartToken.value = res.cart_token
    }
  }

  async function refresh() {
    const api = useApi()
    loading.value = true
    try {
      apply(await api.get<CartResponse>('/cart'))
    } finally {
      loading.value = false
    }
  }

  async function add(variantId: number, qty = 1) {
    const api = useApi()
    loading.value = true
    try {
      apply(await api.post<CartResponse>('/cart/items', { variant_id: variantId, qty }))
      drawerOpen.value = true
    } finally {
      loading.value = false
    }
  }

  async function updateQty(variantId: number, qty: number) {
    const api = useApi()
    loading.value = true
    try {
      apply(await api.patch<CartResponse>('/cart/items', { variant_id: variantId, qty }))
    } finally {
      loading.value = false
    }
  }

  async function remove(variantId: number) {
    const api = useApi()
    loading.value = true
    try {
      apply(await api.del<CartResponse>('/cart/items', { variant_id: variantId }))
    } finally {
      loading.value = false
    }
  }

  return {
    items, quote, itemCount, loading, drawerOpen,
    isEmpty, tier, nextTier, unlockPrompt, freeShipping,
    refresh, add, updateQty, remove,
  }
})
