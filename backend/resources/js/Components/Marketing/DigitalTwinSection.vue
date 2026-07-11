<script setup lang="ts">
import SectionHeading from './SectionHeading.vue'
import ScoreBar from './ScoreBar.vue'
import { useScrollReveal } from '@/composables/useScrollReveal'

const { target, isVisible } = useScrollReveal()

const facts = [
  { label: 'Catalog', value: '847 items' },
  { label: 'Active auctions', value: '12' },
  { label: 'Top channel', value: 'Instagram' },
  { label: 'Best content', value: 'Urgency posts' },
  { label: 'Approval rate', value: '87%' },
  { label: 'Campaigns run', value: '24' },
]

const knowledge = [
  'Silver Age items drive 2–3× the engagement of modern era items for this audience.',
  'Urgency framing outperforms featured-item framing in the 48h before an auction close.',
  'Email performs below expectations for this company. Instagram first.',
]
</script>

<template>
  <section class="py-20 sm:py-28 px-4 sm:px-8 bg-[var(--color-surface-subtle)]">
    <div class="mx-auto max-w-[1280px] grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16 items-start">
      <div>
        <SectionHeading eyebrow="The Business Brain">
          Atlas builds a model of your business,<br />not a generic template for it.
        </SectionHeading>

        <div class="mt-8 space-y-5 text-body-lg text-[var(--color-text-secondary)]">
          <p>
            When you connect your website, Atlas doesn&rsquo;t just read it — it builds a
            structured understanding of what you sell, who your audience is, what&rsquo;s
            performed in the past, and where your current opportunities are.
          </p>
          <p>
            This model — the Business Brain — is what Atlas refers to when it recommends a
            campaign. It knows your catalog. It knows which items drive the most engagement.
            It knows which channels work for your audience. It knows what you said no to last
            time and why.
          </p>
          <p>
            That understanding compounds. Every campaign Atlas runs teaches it something about
            your business. The Business Brain 90 days in is meaningfully smarter than it was on
            day one.
          </p>
          <p>
            This is why Atlas&rsquo;s recommendations improve over time while a generic tool&rsquo;s
            recommendations don&rsquo;t. The tool doesn&rsquo;t know you. Atlas does.
          </p>
        </div>
      </div>

      <div ref="target">
        <figure
          class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-elevated)] p-6 shadow-sm transition-all duration-300 ease-[var(--ease-out)]"
          :class="isVisible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'"
        >
          <div class="flex items-center justify-between mb-5">
            <p class="text-heading-3 text-[var(--color-text-primary)]">CBB Auctions &middot; Business Brain</p>
            <span class="relative flex size-2" aria-hidden="true">
              <span class="absolute inline-flex h-full w-full motion-safe:animate-ping rounded-full bg-[var(--color-accent-500)] opacity-60" />
              <span class="relative inline-flex size-2 rounded-full bg-[var(--color-accent-500)]" />
            </span>
          </div>

          <dl class="grid grid-cols-2 gap-x-4 gap-y-3 mb-5">
            <div v-for="(fact, index) in facts" :key="fact.label" class="transition-opacity duration-300" :style="{ transitionDelay: isVisible ? `${index * 40}ms` : '0ms' }" :class="isVisible ? 'opacity-100' : 'opacity-0'">
              <dt class="text-label-sm uppercase tracking-[0.06em] text-[var(--color-text-muted)]">{{ fact.label }}</dt>
              <dd class="text-body font-medium text-[var(--color-text-primary)] tabular-nums">{{ fact.value }}</dd>
            </div>
          </dl>

          <div class="border-t border-[var(--color-border)] pt-4">
            <p class="text-label uppercase tracking-[0.06em] text-[var(--color-text-muted)] mb-3">
              What Atlas knows
            </p>
            <ul class="space-y-3">
              <li
                v-for="(item, index) in knowledge"
                :key="item"
                class="text-body text-[var(--color-text-secondary)] italic transition-opacity duration-300"
                :style="{ transitionDelay: isVisible ? `${240 + index * 40}ms` : '0ms' }"
                :class="isVisible ? 'opacity-100' : 'opacity-0'"
              >
                &ldquo;{{ item }}&rdquo;
              </li>
            </ul>
          </div>

          <p class="mt-4 text-body-sm text-[var(--color-text-muted)] motion-safe:animate-pulse">
            Last updated: 2 hours ago
          </p>
        </figure>

        <figcaption class="sr-only">
          Business Brain summary panel for CBB Auctions showing catalog size, top channel, approval
          rate, and three plain-language knowledge statements Atlas has synthesized.
        </figcaption>

        <div class="mt-5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-elevated)] p-5">
          <div class="flex items-center justify-between mb-2">
            <p class="text-body font-medium text-[var(--color-text-primary)]">Health: Healthy</p>
          </div>
          <ScoreBar label="Business Brain health score" :value="91" :reveal="isVisible" fill-class="bg-[var(--color-success-text)]" />
          <p class="mt-3 text-body-sm text-[var(--color-text-muted)]">
            Atlas has enough context to recommend with high confidence.
          </p>
        </div>
      </div>
    </div>
  </section>
</template>
