<template>
  <div class="min-h-screen flex items-center justify-center bg-[var(--color-surface)] px-4">
    <div class="w-full max-w-sm">
      <!-- Logo / wordmark -->
      <div class="mb-8 text-center">
        <span class="text-2xl font-semibold text-[var(--color-text-primary)] tracking-tight">Atlas</span>
        <p class="mt-1 text-sm text-[var(--color-text-muted)]">Your AI marketing partner</p>
      </div>

      <div class="bg-[var(--color-surface-elevated)] rounded-xl border border-[var(--color-border)] shadow-sm p-6">
        <slot />
      </div>
    </div>
  </div>
</template>
