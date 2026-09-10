<script setup lang="ts">
import { FileText } from 'lucide-vue-next'

/**
 * The four policies §7 of the materials request requires before the site can
 * take payments: returns, shipping, privacy, terms.
 *
 * The wording is client-supplied and admin-editable. An unpublished policy says
 * so plainly rather than 404ing or — far worse — showing legal text a developer
 * invented. The page is ready the moment the text is pasted into admin.
 */
const route = useRoute()
const api = useApi()

interface Policy {
  slug: string
  title: string
  published: boolean
  body: string | null
  contact_email: string | null
}

const { data, error } = await useAsyncData(`policy-${route.params.slug}`, () =>
  api.get<Policy>(`/content/policies/${route.params.slug}`),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Page not found', fatal: true })
}

const policy = computed(() => data.value!)

useSeoMeta({
  title: () => policy.value.title,
  // An unfinished policy must never be indexed.
  robots: () => (policy.value.published ? 'index,follow' : 'noindex'),
})
</script>

<template>
  <div class="container-content pt-10 pb-20">
    <article class="max-w-[68ch]">
      <h1 class="font-display text-[36px] leading-tight text-ink-900">{{ policy.title }}</h1>

      <div
        v-if="policy.published"
        class="prose-policy mt-6 space-y-4 text-[15px] leading-relaxed text-ink-700 whitespace-pre-line"
      >{{ policy.body }}</div>

      <div v-else class="mt-6 rounded-sm border border-edge-subtle bg-surface-warm p-6">
        <p class="flex items-center gap-2 text-[14px] font-medium text-ink-900">
          <FileText :size="16" aria-hidden="true" /> This policy is being finalised
        </p>
        <p class="mt-2 max-w-[52ch] text-[14px] leading-relaxed text-ink-700">
          We are putting the final wording in place. In the meantime, please get
          in touch and we will answer any question about {{ policy.title.toLowerCase() }}
          directly.
        </p>
        <UiBaseButton to="/contact" variant="secondary" size="sm" class="mt-4">
          Contact us
        </UiBaseButton>
      </div>
    </article>
  </div>
</template>
