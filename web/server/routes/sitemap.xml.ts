/**
 * /sitemap.xml — every page worth a search engine's time, and nothing else.
 *
 * Built on request from the catalogue itself, so a product added in admin is in
 * the sitemap the next time it is fetched, with nothing to regenerate or
 * deploy. Cart, checkout, account and order pages are left out on purpose:
 * they are noindex, and listing a page here while telling crawlers not to index
 * it is a contradiction search consoles complain about.
 *
 * If the API cannot be reached the fixed pages are still served. A short
 * sitemap is a small problem; a 500 on /sitemap.xml gets the whole file
 * reported as broken.
 */

const FIXED = ['/', '/products', '/wholesale', '/about', '/contact']

/** A crawler will take 50,000 URLs; this is a ceiling on the loop, not a target. */
const MAX_PRODUCT_PAGES = 100

const escapeXml = (value: string) =>
  value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const site = String(config.public.siteUrl).replace(/\/+$/, '')
  const api = `${String(config.public.apiBase).replace(/\/+$/, '')}/api/v1`

  const paths = new Set<string>(FIXED)

  try {
    const [categories, store] = await Promise.all([
      $fetch<{ data: Array<{ slug: string; children?: Array<{ slug: string }> }> }>(`${api}/categories`),
      $fetch<{ policies?: Array<{ slug: string; published: boolean }> }>(`${api}/content/store`),
    ])

    for (const category of categories.data ?? []) {
      paths.add(`/${category.slug}`)
      for (const child of category.children ?? []) paths.add(`/${child.slug}`)
    }

    // Only the policies with wording behind them. An unpublished one is a page
    // that says its text is pending, which is not worth sending anyone to.
    for (const policy of store.policies ?? []) {
      if (policy.published) paths.add(`/policies/${policy.slug}`)
    }

    for (let page = 1; page <= MAX_PRODUCT_PAGES; page++) {
      const products = await $fetch<{
        data: Array<{ slug: string }>
        meta?: { last_page?: number }
      }>(`${api}/products`, { query: { per_page: 60, page } })

      for (const product of products.data ?? []) paths.add(`/products/${product.slug}`)

      if (!products.data?.length || page >= (products.meta?.last_page ?? 1)) break
    }
  } catch {
    // Served short rather than not at all — see above.
  }

  const urls = [...paths]
    .map((path) => `  <url><loc>${escapeXml(site + path)}</loc></url>`)
    .join('\n')

  setHeader(event, 'content-type', 'application/xml; charset=utf-8')
  setHeader(event, 'cache-control', 'public, max-age=3600')

  return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls}\n</urlset>\n`
})
