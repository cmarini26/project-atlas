<script setup lang="ts">
import MarketingButton from './MarketingButton.vue'
import { useScrollReveal } from '@/composables/useScrollReveal'

const { target, isVisible } = useScrollReveal()
</script>

<template>
  <section class="py-20 sm:py-28 px-4 sm:px-8 bg-[var(--color-surface-overlay)]">
    <div
      ref="target"
      class="mx-auto max-w-[720px] text-center transition-opacity duration-300 ease-[var(--ease-out)]"
      :class="isVisible ? 'opacity-100' : 'opacity-0'"
    >
      <h2 class="text-display sm:text-[2.25rem] text-[var(--color-text-inverse)] mb-6 text-balance">
        Connect your business.<br />See your first recommendation.
      </h2>

      <p class="text-body-lg text-stone-300 mb-8">
        Enter your website URL. Atlas handles the rest. Your first recommendation is ready in
        minutes — with a complete explanation of why it chose it.
      </p>

      <MarketingButton href="/register" variant="primary" size="lg">
        Connect Your Business — It&rsquo;s Free
      </MarketingButton>

      <p class="mt-5 text-body-sm text-stone-400">
        No credit card required. &middot; No configuration needed.
      </p>
    </div>
  </section>
</template>
