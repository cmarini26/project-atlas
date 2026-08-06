<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
</script>

<template>
  <div class="min-h-screen bg-[var(--color-surface)] text-[var(--color-text-primary)]">
    <header class="border-b border-[var(--color-border)] px-4 py-5 sm:px-8">
      <div class="mx-auto flex max-w-3xl items-center justify-between">
        <Link href="/" class="text-lg font-semibold">The Clear Move</Link>
        <Link href="/login" class="text-sm text-[var(--color-text-link)] hover:underline">Sign in</Link>
      </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-12 sm:px-8 sm:py-16">
      <slot />
    </main>

    <footer class="border-t border-[var(--color-border)] px-4 py-8 sm:px-8">
      <div class="mx-auto flex max-w-3xl flex-wrap gap-x-5 gap-y-2 text-sm text-[var(--color-text-muted)]">
        <span>&copy; 2026 The Clear Move</span>
        <Link href="/privacy" class="hover:text-[var(--color-text-primary)]">Privacy Policy</Link>
        <Link href="/terms" class="hover:text-[var(--color-text-primary)]">Terms of Service</Link>
      </div>
    </footer>
  </div>
</template>
