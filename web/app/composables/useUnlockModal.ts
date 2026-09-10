/**
 * The wholesale unlock prompt.
 *
 * Every lock on the site — a product card, a product page, the sticky bar —
 * opens the same dialog rather than navigating away. A guest who clicks a lock
 * is mid-browse; sending them to a full registration page costs the page they
 * were looking at, which is the moment that motivated the click.
 *
 * State lives in useState so the dialog can be mounted once in the layout and
 * opened from anywhere, and so it is SSR-safe (never shared between requests).
 */
export interface UnlockModalContext {
  /** Thumbnail of whatever the shopper was looking at, shown in the dialog. */
  image?: { path: string; alt: string } | null
}

export function useUnlockModal() {
  const isOpen = useState('unlock-modal-open', () => false)
  const context = useState<UnlockModalContext>('unlock-modal-context', () => ({}))

  function show(ctx: UnlockModalContext = {}) {
    context.value = ctx
    isOpen.value = true
  }

  function hide() {
    isOpen.value = false
  }

  return { isOpen, context, show, hide }
}
