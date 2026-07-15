<script setup lang="ts">
withDefaults(
  defineProps<{
    modelValue: string
    id?: string
    rows?: number
    placeholder?: string
    invalid?: boolean
  }>(),
  {
    id: undefined,
    rows: 3,
    placeholder: undefined,
    invalid: false,
  },
)

defineEmits<{ 'update:modelValue': [string] }>()
</script>

<template>
  <textarea
    :id="id"
    :value="modelValue"
    :rows="rows"
    :placeholder="placeholder"
    :class="[
      'w-full px-3 py-2 text-sm rounded-[var(--radius-sm)] border bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] placeholder-[var(--color-text-placeholder)] transition-colors duration-[var(--duration-fast)]',
      invalid
        ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
        : 'border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]',
    ]"
    @input="$emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
  />
</template>
