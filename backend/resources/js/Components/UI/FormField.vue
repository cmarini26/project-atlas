<script setup lang="ts">
withDefaults(
  defineProps<{
    label: string
    for?: string
    optional?: boolean
    hint?: string
    error?: string
    uppercaseLabel?: boolean
  }>(),
  {
    for: undefined,
    optional: false,
    hint: undefined,
    error: undefined,
    uppercaseLabel: true,
  },
)
</script>

<template>
  <div>
    <label
      :for="$props.for"
      :class="[
        'block mb-1.5 text-[var(--color-text-muted)]',
        uppercaseLabel
          ? 'text-xs font-medium uppercase tracking-widest'
          : 'text-xs',
      ]"
    >
      {{ label }}
      <span v-if="optional" class="text-[var(--color-text-muted)] normal-case">(optional)</span>
    </label>
    <p v-if="hint" class="mb-1.5 text-xs text-[var(--color-text-muted)]">{{ hint }}</p>
    <slot />
    <p v-if="error" class="mt-1 text-xs text-rose-600">{{ error }}</p>
  </div>
</template>
