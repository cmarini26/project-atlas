<script setup lang="ts">
withDefaults(
  defineProps<{
    modelValue: string
    id?: string
    required?: boolean
    invalid?: boolean
    disabled?: boolean
    placeholder?: string
  }>(),
  {
    id: undefined,
    required: false,
    invalid: false,
    disabled: false,
    placeholder: undefined,
  },
)

defineEmits<{ 'update:modelValue': [string] }>()
</script>

<template>
  <select
    :id="id"
    :value="modelValue"
    :required="required"
    :disabled="disabled"
    :class="[
      'w-full px-3 py-2 text-sm rounded-[var(--radius-sm)] border bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] transition-colors duration-[var(--duration-fast)]',
      invalid
        ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
        : 'border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]',
      disabled ? 'opacity-60 cursor-not-allowed' : '',
    ]"
    @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
  >
    <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
    <slot />
  </select>
</template>
