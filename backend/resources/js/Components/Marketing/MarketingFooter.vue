<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
</script>

<template>
  <footer class="border-t border-[var(--color-border)] px-4 sm:px-8 py-14">
    <div class="mx-auto max-w-[1280px] grid grid-cols-1 sm:grid-cols-2 gap-10">
      <div>
        <span class="text-heading-2 font-semibold text-[var(--color-text-primary)]">Atlas</span>
        <p class="mt-2 max-w-xs text-body-sm text-[var(--color-text-muted)]">
          AI marketing for independent businesses. Not a tool that waits for you.
        </p>
      </div>

      <div>
        <p class="text-label uppercase tracking-[0.06em] text-[var(--color-text-muted)] mb-3">Product</p>
        <ul class="space-y-2">
          <li>
            <a href="#how-it-works" class="text-body text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
              How It Works
            </a>
          </li>
          <li>
            <a href="#industries" class="text-body text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
              Industries
            </a>
          </li>
          <li>
            <Link href="/login" class="text-body text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]">
              Sign In
            </Link>
          </li>
        </ul>
      </div>
    </div>

    <div class="mx-auto max-w-[1280px] mt-10 pt-6 border-t border-[var(--color-border)]">
      <p class="text-body-sm text-[var(--color-text-muted)]">&copy; 2026 Atlas. All rights reserved.</p>
    </div>
  </footer>
</template>
