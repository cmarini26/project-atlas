<script setup lang="ts">
import SectionHeading from './SectionHeading.vue'
import MarketingButton from './MarketingButton.vue'
import { useScrollReveal } from '@/composables/useScrollReveal'

const { target, isVisible } = useScrollReveal()

const steps = [
  { name: 'Observe', description: 'Atlas connects to your website and scans your catalog, inventory, and marketing signals continuously.' },
  { name: 'Understand', description: 'Facts are extracted from each observation and synthesized into structured knowledge about your business.' },
  { name: 'Decide', description: 'The Opportunity Engine identifies the highest-signal marketing moment — the one item, auction, or event that deserves attention right now.' },
  { name: 'Recommend', description: 'Atlas prepares a complete campaign strategy with a rationale that explains every choice.' },
  { name: 'Prepare', description: 'Campaign content is generated: posts, subject lines, email body, and channel selection — ready for review.' },
  { name: 'Approve', description: 'You read the rationale, review the content, and approve, edit, or pass. Nothing is published without this step.' },
  { name: 'Execute', description: 'Atlas queues the approved campaign for delivery across your connected channels.' },
  { name: 'Measure', description: 'Actual reach and engagement are compared against what Atlas predicted. The record is permanent.' },
  { name: 'Learn', description: 'Every approval, edit, and outcome feeds back into Atlas’s understanding of your business. The next recommendation is more precise.' },
]

function stepDelay(index: number): string {
  if (index < 5) return `${index * 40}ms`
  if (index === 5) return `${index * 40 + 100}ms`
  return `${index * 40 + 200}ms`
}
</script>

<template>
  <section id="how-it-works" class="py-20 sm:py-28 px-4 sm:px-8">
    <div class="mx-auto max-w-[1280px]">
      <SectionHeading eyebrow="The Atlas loop" align="center" class="mb-14">
        Nine steps. Continuous.<br />Yours to approve at every stage.
      </SectionHeading>

      <div ref="target" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <div
          v-for="(step, index) in steps"
          :key="step.name"
          :class="[
            'rounded-xl p-6 transition-all duration-300 ease-[var(--ease-out)]',
            isVisible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-1.5',
            index === 5
              ? 'bg-[var(--color-accent-50)] border-2 border-[var(--color-accent-500)]'
              : 'bg-[var(--color-surface-subtle)] border border-transparent',
          ]"
          :style="{ transitionDelay: isVisible ? stepDelay(index) : '0ms' }"
        >
          <p
            class="text-label-sm font-medium mb-2 tabular-nums"
            :class="index === 5 ? 'text-[var(--color-accent-600)]' : 'text-[var(--color-text-muted)]'"
          >
            {{ String(index + 1).padStart(2, '0') }}
          </p>
          <h3 class="text-heading-3 text-[var(--color-text-primary)] mb-2">{{ step.name }}</h3>
          <p class="text-body text-[var(--color-text-secondary)]">{{ step.description }}</p>
        </div>
      </div>

      <p class="mt-8 text-center text-body-sm text-[var(--color-text-muted)]">
        The loop closes back to Observe — Atlas keeps watching after every campaign.
      </p>

      <div class="mt-10 flex justify-center">
        <MarketingButton href="/register" variant="primary" size="lg">
          Connect Your Business — It&rsquo;s Free
        </MarketingButton>
      </div>
    </div>
  </section>
</template>
