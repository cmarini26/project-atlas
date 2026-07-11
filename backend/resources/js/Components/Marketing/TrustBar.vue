<script setup lang="ts">
const signals = [
  'Designed with CBB Auctions',
  'Built for independent businesses',
  'No card required to start',
  'Website → recommendation in minutes',
]
</script>

<template>
  <section class="border-y border-[var(--color-border)] bg-[var(--color-surface-subtle)]">
    <div class="mx-auto max-w-[1280px] px-4 sm:px-8 py-6">
      <ul class="flex flex-wrap items-center justify-center gap-x-3 gap-y-2 text-center">
        <template v-for="(signal, index) in signals" :key="signal">
          <li class="text-body-sm text-[var(--color-text-secondary)]">{{ signal }}</li>
          <li v-if="index < signals.length - 1" aria-hidden="true" class="text-[var(--color-border-strong)]">
            &middot;
          </li>
        </template>
      </ul>
    </div>
  </section>
</template>
