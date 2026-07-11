<script setup lang="ts">
import SectionHeading from './SectionHeading.vue'
import MarketingButton from './MarketingButton.vue'
import { useScrollReveal } from '@/composables/useScrollReveal'

const { target, isVisible } = useScrollReveal()
</script>

<template>
  <section class="py-20 sm:py-28 px-4 sm:px-8">
    <div
      ref="target"
      class="mx-auto max-w-[720px] text-center transition-opacity duration-300 ease-[var(--ease-out)]"
      :class="isVisible ? 'opacity-100' : 'opacity-0'"
    >
      <SectionHeading eyebrow="Built with a real business" align="center">
        CBB Auctions helped shape Atlas from day one.
      </SectionHeading>

      <p class="mt-6 text-body-lg text-[var(--color-text-secondary)]">
        Atlas&rsquo;s comic book and collectibles support wasn&rsquo;t designed in the abstract —
        it was built directly with CBB Auctions, a real comic book auction house, as a design
        partner. The auction cycles, CGC grading language, and urgency windows described on this
        page reflect how their business actually runs.
      </p>

      <p class="mt-4 text-body text-[var(--color-text-muted)]">
        Atlas is early. We&rsquo;d rather tell you that plainly than invent customer quotes and
        numbers we don&rsquo;t have yet.
      </p>

      <div class="mt-8 flex justify-center">
        <MarketingButton href="/register" variant="primary" size="md">
          Connect Your Business — It&rsquo;s Free
        </MarketingButton>
      </div>
    </div>
  </section>
</template>
