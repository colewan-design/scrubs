/**
 * Ship or pick up — chosen on the cart page, honoured by checkout.
 *
 * A cookie rather than shared component state: the cart hands over to checkout
 * through a full navigation, and a shopper who picked "local pickup" on the
 * cart and then landed on a shipping-address form would reasonably conclude the
 * choice was ignored.
 *
 * It is only ever a *preference*. Nothing is priced from it here — checkout
 * re-quotes the order from this value server-side, and the order is re-quoted
 * again when it is placed.
 */
export type FulfillmentType = 'ship' | 'pickup'

export function useFulfillmentPreference() {
  return useCookie<FulfillmentType>('bsd_fulfillment', {
    default: () => 'ship',
    maxAge: 60 * 60 * 24 * 30,
    sameSite: 'lax',
  })
}
