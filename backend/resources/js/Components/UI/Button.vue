<script setup lang="ts">
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
    size?: 'sm' | 'md'
    fullWidth?: boolean
    as?: 'button' | 'a'
    href?: string
    type?: 'button' | 'submit'
    disabled?: boolean
    loading?: boolean
  }>(),
  {
    variant: 'primary',
    size: 'md',
    fullWidth: false,
    as: 'button',
    href: undefined,
    type: 'button',
    disabled: false,
    loading: false,
  },
)

defineEmits<{ click: [MouseEvent] }>()

const variantClasses: Record<string, string> = {
  primary: 'bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] shadow-[var(--shadow-card)]',
  secondary: 'border border-[var(--color-border-strong)] bg-[var(--color-surface-elevated)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-panel)] hover:text-[var(--color-text-primary)]',
  ghost: 'text-[var(--color-text-link)] hover:bg-[var(--color-accent-50)] hover:underline',
  danger: 'bg-rose-600 text-white hover:bg-rose-700',
}

const sizeClasses: Record<string, string> = {
  sm: 'py-2 px-3 text-sm',
  md: 'py-2.5 px-4 text-sm',
}
</script>

<template>
  <component
    :is="as"
    :href="as === 'a' ? href : undefined"
    :type="as === 'button' ? type : undefined"
    :disabled="as === 'button' ? disabled || loading : undefined"
    :class="[
      'inline-flex items-center justify-center gap-2 font-semibold rounded-[var(--radius-sm)] transition-colors duration-[var(--duration-fast)]',
      variantClasses[variant],
      variant !== 'ghost' ? sizeClasses[size] : ['rounded-[var(--radius-sm)]', sizeClasses[size]],
      fullWidth ? 'w-full' : '',
      disabled || loading ? 'opacity-60 cursor-not-allowed' : '',
    ]"
    @click="(event: MouseEvent) => !disabled && !loading && $emit('click', event)"
  >
    <slot name="icon" />
    <slot />
  </component>
</template>
