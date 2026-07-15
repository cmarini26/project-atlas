<script setup lang="ts">
withDefaults(
  defineProps<{
    modelValue: boolean
    size?: 'sm' | 'md'
  }>(),
  { size: 'md' },
)

defineEmits<{ 'update:modelValue': [boolean] }>()

const sizeClasses: Record<string, string> = {
  sm: 'px-2 py-1.5 text-xs gap-1.5',
  md: 'px-3 py-2 text-sm gap-2',
}
</script>

<template>
  <label
    :class="[
      'flex items-center rounded-[var(--radius-sm)] border cursor-pointer transition-colors duration-[var(--duration-fast)]',
      sizeClasses[size],
      modelValue
        ? 'border-[var(--color-accent-500)] bg-[var(--color-accent-50)] text-[var(--color-text-primary)]'
        : 'border-[var(--color-border)] bg-[var(--color-surface-elevated)] text-[var(--color-text-secondary)]',
    ]"
  >
    <input
      type="checkbox"
      class="size-4 rounded border-[var(--color-border-strong)] text-[var(--color-accent-600)] focus:ring-1 focus:ring-[var(--color-border-focus)]"
      :checked="modelValue"
      @change="$emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
    />
    <slot />
  </label>
</template>
