<script setup lang="ts">
/**
 * One checkout field, drawn as the redesign draws it: the label lives inside
 * the box, above the value, so a filled form reads as a list of facts rather
 * than a column of unlabelled boxes.
 *
 * The label is a real <label> tied to the control by id — the "label inside the
 * box" is a visual arrangement, not a placeholder standing in for one.
 *
 * Pass a control through the default slot for anything that is not a plain
 * input (the province <select>); the slot is handed the id and the class the
 * built-in input uses, so both look identical.
 */
const model = defineModel<string>()

defineProps<{
  label: string
  /** Marks the field optional instead of drawing the required asterisk. */
  optional?: boolean
  error?: string
  type?: string
  autocomplete?: string
  placeholder?: string
  uppercase?: boolean
}>()

const id = useId()

const control =
  'h-6 w-full border-0 bg-transparent p-0 text-[15px] text-ink-900 placeholder:text-ink-400 focus:outline-none'
</script>

<template>
  <div>
    <div
      class="rounded-sm border bg-white px-3.5 py-2 transition-colors focus-within:border-edge-strong"
      :class="error ? 'border-status-error' : 'border-edge'"
    >
      <label :for="id" class="block text-[11px] text-ink-500">
        {{ label }}
        <span v-if="optional" class="text-ink-400">(optional)</span>
        <span v-else class="text-ink-400" aria-hidden="true">*</span>
      </label>

      <slot :id="id" :control="control">
        <input
          :id="id"
          v-model="model"
          :type="type ?? 'text'"
          :autocomplete="autocomplete"
          :placeholder="placeholder"
          :aria-invalid="error ? 'true' : undefined"
          :class="[control, uppercase && 'uppercase']"
        >
      </slot>
    </div>

    <p v-if="error" class="mt-1 text-[12px] text-status-error">{{ error }}</p>
  </div>
</template>
