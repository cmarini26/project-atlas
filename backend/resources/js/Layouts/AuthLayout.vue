<template>
  <div class="min-h-screen flex items-center justify-center bg-[var(--color-surface)] px-4 py-8">
    <div class="w-full max-w-lg">
      <!-- Logo / wordmark -->
      <div class="mb-8 flex flex-col items-center text-center">
        <span class="size-11 rounded-[var(--radius-md)] bg-[var(--color-accent-50)] text-[var(--color-accent-700)] grid place-items-center text-lg font-bold border border-[var(--color-accent-200)] shadow-[var(--shadow-xs)]">C</span>
        <span class="mt-3 text-2xl font-semibold text-[var(--color-text-primary)] tracking-normal">The Clear Move</span>
        <p class="mt-1 text-sm text-[var(--color-text-muted)]">Autonomous marketing operating system</p>
      </div>

      <div class="bg-[var(--color-surface-elevated)] rounded-[var(--radius-lg)] border border-[var(--color-border)] shadow-[var(--shadow-raised)] p-6 sm:p-7">
        <slot />
      </div>
    </div>
  </div>
</template>
