<template>
  <div class="min-h-screen flex items-center justify-center bg-[--color-surface] px-4">
    <div class="w-full max-w-sm">
      <!-- Logo / wordmark -->
      <div class="mb-8 text-center">
        <span class="text-2xl font-semibold text-[--color-text-primary] tracking-tight">Atlas</span>
        <p class="mt-1 text-sm text-[--color-text-muted]">Your AI marketing partner</p>
      </div>

      <div class="bg-[--color-surface-elevated] rounded-xl border border-[--color-border] shadow-sm p-6">
        <slot />
      </div>
    </div>
  </div>
</template>
