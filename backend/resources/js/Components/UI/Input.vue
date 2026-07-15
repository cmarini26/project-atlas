<script setup lang="ts">
withDefaults(
  defineProps<{
    modelValue: string
    id?: string
    type?: 'text' | 'email' | 'url' | 'password'
    placeholder?: string
    required?: boolean
    invalid?: boolean
    disabled?: boolean
  }>(),
  {
    id: undefined,
    type: 'text',
    placeholder: undefined,
    required: false,
    invalid: false,
    disabled: false,
  },
)

defineEmits<{ 'update:modelValue': [string] }>()
</script>

<template>
  <input
    :id="id"
    :type="type"
    :value="modelValue"
    :placeholder="placeholder"
    :required="required"
    :disabled="disabled"
    :class="[
      'w-full px-3 py-2 text-sm rounded-[var(--radius-sm)] border bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] placeholder-[var(--color-text-placeholder)] transition-colors duration-[var(--duration-fast)]',
      invalid
        ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
        : 'border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]',
      disabled ? 'opacity-60 cursor-not-allowed' : '',
    ]"
    @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
  />
</template>
