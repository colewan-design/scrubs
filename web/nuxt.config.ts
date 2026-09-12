import tailwindcss from '@tailwindcss/vite'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2026-09-02',
  devtools: { enabled: true },

  css: ['~/assets/css/main.css'],

  modules: ['@pinia/nuxt'],

  vite: {
    plugins: [tailwindcss()],
  },

  // SSR is not optional here: category and product pages must be indexable,
  // and the design is image-heavy enough that CSR would hurt first paint.
  ssr: true,

  // Deployed to a Node process on the VPS behind Nginx.
  nitro: {
    preset: 'node-server',
  },

  runtimeConfig: {
    public: {
      // Same-origin in production (/api proxied by Nginx to Laravel) so Sanctum
      // uses first-party cookies — no CORS, no cross-site cookie problems.
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000',
      // Also the Origin sent on SSR API calls, so whatever this resolves to
      // must appear in the API's SANCTUM_STATEFUL_DOMAINS or the session is
      // dropped and every server render comes out as a guest.
      siteUrl: process.env.NUXT_PUBLIC_SITE_URL || 'https://bulkscrubsdirect.ca',
      currency: 'CAD',
    },
  },

  app: {
    head: {
      htmlAttrs: { lang: 'en-CA' },
      // Fallback for any page that sets no title of its own. The suffix
      // template is applied in app.vue — see the note there.
      title: 'BulkScrubsDirect',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'theme-color', content: '#ffffff' },
        { property: 'og:site_name', content: 'BulkScrubsDirect' },
      ],
      link: [
        // Drawn as type rather than shipped art, matching the header lockup —
        // replaced when the client supplies the real mark (materials request §3).
        { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' },
        { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
        { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
        {
          rel: 'stylesheet',
          // Caveat carries exactly one string on the site — the hero's
          // "Healthcare looks good on you" flourish — so it rides along in the
          // same stylesheet request rather than earning a second one.
          href: 'https://fonts.googleapis.com/css2?family=Caveat:wght@600&family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap',
        },
      ],
    },
  },

  typescript: {
    strict: true,
  },
})
