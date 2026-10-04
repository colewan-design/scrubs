<script setup lang="ts">
import { MapPin } from 'lucide-vue-next'
import type { AddressSuggestion, ResolvedAddress } from '~/composables/useApi'

/**
 * The street-address input, with suggestions underneath as the customer types.
 *
 * It goes in a CheckoutField's default slot and takes that field's id and
 * control class, so it is drawn exactly like the inputs around it. Picking a
 * suggestion emits the whole address broken into fields; what to do with them
 * is the form's business, not this input's.
 *
 * It is still an ordinary text field first. With no suggestions — none match,
 * the lookup is not configured, the network is down — it behaves precisely as
 * the plain input it replaced, and the browser's own saved-address autofill
 * keeps working because the autocomplete token is unchanged.
 *
 * Keyboard and screen-reader behaviour follow the ARIA combobox pattern: the
 * arrow keys move through the list while focus stays in the input, Enter
 * picks, Escape dismisses, and the number of suggestions is announced.
 *
 * The list is positioned against the CheckoutField box, which is `relative`
 * for exactly this purpose, so it lines up with the field's border rather than
 * with the text inside it.
 */
const model = defineModel<string>({ default: '' })

defineProps<{
  id: string
  /** The class CheckoutField gives its own input. */
  control: string
  autocomplete?: string
  invalid?: boolean
}>()

const emit = defineEmits<{
  resolved: [address: ResolvedAddress]
}>()

const lookup = useAddressLookup()
const { suggestions, attribution } = lookup

const listId = useId()
const open = ref(false)
/** Index of the highlighted suggestion; -1 while none is. */
const active = ref(-1)
const announcement = ref('')

const optionId = (i: number) => `${listId}-${i}`

watch(suggestions, (found) => {
  active.value = -1
  open.value = found.length > 0

  if (found.length) {
    announcement.value = `${found.length} address ${found.length === 1 ? 'suggestion' : 'suggestions'} available. `
      + 'Use the up and down arrow keys to choose one.'
  }
})

function close() {
  open.value = false
  active.value = -1
}

function onInput(event: Event) {
  // Only typing (and pasting) is a question. A browser filling the whole form
  // from a saved address also lands here, and suggesting alternatives to an
  // address that was just filled in complete would be noise. Chrome sends
  // that as a plain Event; Firefox and Safari as a replacement.
  if (!(event instanceof InputEvent) || event.inputType === 'insertReplacementText') {
    lookup.clear()

    return
  }

  lookup.search((event.target as HTMLInputElement).value)
}

async function pick(suggestion: AddressSuggestion) {
  const typed = model.value

  close()

  const address = await lookup.resolve(suggestion, typed)

  // Nothing came back: leave what was typed exactly as it is. The customer
  // carries on by hand, which is better than a half-filled form.
  if (!address) return

  announcement.value = 'Address filled in. Please check the city, province and postal code.'
  emit('resolved', address)
}

function onKeydown(event: KeyboardEvent) {
  if (!open.value) return

  const last = suggestions.value.length - 1

  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      active.value = active.value >= last ? 0 : active.value + 1
      break

    case 'ArrowUp':
      event.preventDefault()
      active.value = active.value <= 0 ? last : active.value - 1
      break

    case 'Enter': {
      // Always swallowed while the list is showing. Enter in this form places
      // the order, and that must not be what happens to someone who is in the
      // middle of choosing an address.
      event.preventDefault()

      const chosen = suggestions.value[active.value]

      if (chosen) pick(chosen)
      else close()
      break
    }

    case 'Escape':
      event.preventDefault()
      close()
      break
  }
}

/** Leaving the field ends the lookup, including a reply still on its way. */
function onBlur() {
  lookup.clear()
  close()
}
</script>

<template>
  <input
    :id="id"
    v-model="model"
    type="text"
    role="combobox"
    aria-autocomplete="list"
    :aria-expanded="open"
    :aria-controls="open ? listId : undefined"
    :aria-activedescendant="open && active >= 0 ? optionId(active) : undefined"
    :aria-invalid="invalid ? 'true' : undefined"
    :autocomplete="autocomplete"
    :class="control"
    @input="onInput"
    @keydown="onKeydown"
    @blur="onBlur"
  >

  <!-- mousedown is cancelled so that pressing a suggestion does not blur the
       input first — the blur would close the list before the click landed. -->
  <div
    v-if="open"
    class="absolute -inset-x-px top-full z-20 mt-1 overflow-hidden rounded-sm border border-edge bg-white shadow-pop"
    @mousedown.prevent
  >
    <ul :id="listId" role="listbox" aria-label="Address suggestions">
      <li
        v-for="(suggestion, i) in suggestions"
        :id="optionId(i)"
        :key="suggestion.id"
        role="option"
        :aria-selected="i === active"
        class="flex cursor-pointer items-start gap-2.5 px-3.5 py-2.5"
        :class="i === active && 'bg-surface-warm'"
        @mousemove="active = i"
        @click="pick(suggestion)"
      >
        <MapPin :size="15" class="mt-0.5 shrink-0 text-ink-400" aria-hidden="true" />
        <span class="min-w-0">
          <span class="block truncate text-[14px] text-ink-900">{{ suggestion.primary }}</span>
          <span v-if="suggestion.secondary" class="block truncate text-[12px] text-ink-500">
            {{ suggestion.secondary }}
          </span>
        </span>
      </li>
    </ul>

    <!-- Whoever supplied the suggestions requires the credit beside them. The
         type is set to Google's rules, the stricter of the two providers:
         Roboto or a plain sans, regular weight, this grey, never wrapped. -->
    <p
      v-if="attribution"
      class="border-t border-edge-subtle px-3.5 py-1.5 text-right text-[12px] font-normal whitespace-nowrap text-[#5e5e5e]"
      style="font-family: Roboto, Arial, sans-serif"
      translate="no"
    >
      {{ attribution }}
    </p>
  </div>

  <span class="sr-only" role="status" aria-live="polite">{{ announcement }}</span>
</template>
