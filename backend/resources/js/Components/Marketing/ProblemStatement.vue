<script setup lang="ts">
import { useScrollReveal } from '@/composables/useScrollReveal'

const { target, isVisible } = useScrollReveal()
</script>

<template>
  <section class="py-20 sm:py-28 px-4 sm:px-8">
    <div
      ref="target"
      class="mx-auto max-w-[720px] text-center transition-opacity duration-[250ms] ease-[var(--ease-out)]"
      :class="isVisible ? 'opacity-100' : 'opacity-0'"
    >
      <p class="text-label uppercase tracking-[0.06em] text-[var(--color-text-muted)] mb-4">
        The problem with marketing small businesses
      </p>

      <h2 class="text-heading-1 sm:text-display text-[var(--color-text-primary)] mb-8 text-balance">
        You know your business.<br />You just don&rsquo;t have time to market it.
      </h2>

      <div class="space-y-6 text-body-lg text-[var(--color-text-secondary)] text-left">
        <p>
          Most marketing tools are built for marketing teams. They assume you have a campaign
          manager, a copywriter, a scheduler, and two hours on a Tuesday to set everything up
          properly.
        </p>

        <p class="text-heading-3 text-[var(--color-text-primary)] py-2">You don&rsquo;t.</p>

        <p>
          You have 30 minutes on a Sunday evening, a week of inventory that needs promoting,
          three social channels you post to inconsistently, and a sense that you&rsquo;re
          leaving money on the table — but not enough time to figure out exactly where.
        </p>

        <p>
          The tools that exist either require marketing expertise you don&rsquo;t have, or
          generate generic content that doesn&rsquo;t know anything about your business.
        </p>

        <p>Neither works.</p>
      </div>

      <p class="mt-10 text-heading-2 text-[var(--color-text-primary)]">
        Atlas is built for the 30-minute window.
      </p>
    </div>
  </section>
</template>
