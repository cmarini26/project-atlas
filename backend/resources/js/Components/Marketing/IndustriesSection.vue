<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { ArrowRightIcon } from '@heroicons/vue/24/outline'
import SectionHeading from './SectionHeading.vue'
import { useScrollReveal } from '@/composables/useScrollReveal'

const { target, isVisible } = useScrollReveal()

const industries = [
  {
    heading: 'Comic Book Auctions & Collectibles',
    context:
      'Atlas understands auction cycles, CGC grades, key issues, and the 48-hour urgency window that drives serious bidder behavior.',
    detects: [
      'Auctions closing in the next 48–72 hours',
      'High-grade items that haven’t been featured recently',
      'New inventory arriving after a collection acquisition',
      'Re-engagement windows after a slow auction cycle',
    ],
    cta: 'Start with your auction house',
  },
  {
    heading: 'Exotic & Used Car Dealerships',
    context:
      'Atlas monitors inventory age, unit price, and market timing to surface the right vehicle at the right moment — before it sits unsold.',
    detects: [
      'New high-value arrivals that haven’t been promoted',
      'Inventory approaching the 60-day threshold (pricing risk window)',
      'Featured vehicle rotation opportunities',
      'Model-specific demand signals',
    ],
    cta: 'Start with your dealership',
  },
]
</script>

<template>
  <section id="industries" class="py-20 sm:py-28 px-4 sm:px-8 bg-[var(--color-surface-subtle)]">
    <div class="mx-auto max-w-[1280px]">
      <SectionHeading eyebrow="Built for businesses like yours" align="center" class="mb-14">
        Atlas understands your inventory,<br />your timing, and your audience.
      </SectionHeading>

      <div ref="target" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div
          v-for="(industry, index) in industries"
          :key="industry.heading"
          class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-elevated)] p-6 sm:p-8 transition-all duration-300 ease-[var(--ease-out)]"
          :class="isVisible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'"
          :style="{ transitionDelay: isVisible ? `${index * 50}ms` : '0ms' }"
        >
          <h3 class="text-heading-2 text-[var(--color-text-primary)] mb-3">{{ industry.heading }}</h3>
          <p class="text-body text-[var(--color-text-secondary)] mb-5">{{ industry.context }}</p>

          <p class="text-label uppercase tracking-[0.06em] text-[var(--color-text-muted)] mb-3">
            What Atlas detects
          </p>
          <ul class="space-y-2 mb-6">
            <li v-for="item in industry.detects" :key="item" class="flex gap-2 text-body text-[var(--color-text-secondary)]">
              <span class="text-[var(--color-accent-500)]" aria-hidden="true">&middot;</span>
              <span>{{ item }}</span>
            </li>
          </ul>

          <Link
            href="/register"
            class="inline-flex items-center gap-1.5 text-body font-medium text-[var(--color-accent-600)] hover:text-[var(--color-accent-700)]"
          >
            {{ industry.cta }}
            <ArrowRightIcon class="size-4" aria-hidden="true" />
          </Link>
        </div>
      </div>
    </div>
  </section>
</template>
