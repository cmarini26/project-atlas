<script setup lang="ts">
import SectionHeading from './SectionHeading.vue'
import ScoreBar from './ScoreBar.vue'
import { useScrollReveal } from '@/composables/useScrollReveal'
import { useCountUp } from '@/composables/useCountUp'

const { target, isVisible } = useScrollReveal()

const facts = useCountUp(284, isVisible)
const knowledge = useCountUp(61, isVisible)
const campaigns = useCountUp(11, isVisible)
</script>

<template>
  <section class="py-20 sm:py-28 px-4 sm:px-8">
    <div class="mx-auto max-w-[1280px] grid grid-cols-1 lg:grid-cols-[3fr_2fr] gap-12 lg:gap-16 items-center">
      <div>
        <SectionHeading eyebrow="Atlas learns your business">
          The recommendation in month three<br />is better than the one in week one.
        </SectionHeading>

        <div class="mt-8 space-y-5 text-body-lg text-[var(--color-text-secondary)]">
          <p>
            When Atlas makes its first recommendation, it knows what it found on your website.
            That is enough to get started — but not enough to be great.
          </p>
          <p class="text-heading-3 text-[var(--color-text-primary)]">What changes it:</p>
          <p>
            Every time you approve, Atlas records what worked. Every time you pass, Atlas records
            what to avoid. Every time you edit a sentence, Atlas records the pattern — this
            business adds prices, removes hashtags, prefers concrete language over abstract
            claims.
          </p>
          <p>
            Every time a campaign runs, Atlas compares what it predicted to what actually
            happened. When it was right, it reinforces the signal. When it was wrong, it adjusts.
          </p>
          <p>
            After 90 days, Atlas knows which channel actually drives results for your audience,
            which content style your customers respond to, which types of campaigns have
            overperformed, which ones have consistently underperformed, and which times of day
            reach the most people.
          </p>
          <p>
            None of this requires you to configure anything. It accumulates automatically, as a
            consequence of running your business with Atlas.
          </p>
        </div>

        <blockquote class="mt-8 border-l-4 border-[var(--color-accent-200)] pl-5 text-body-lg text-[var(--color-text-secondary)] italic">
          &ldquo;This is the idea behind Atlas: the system serving your business in month three
          should already be measurably sharper than the one that met you on day one — because
          it&rsquo;s specific to you, not a generic template.&rdquo;
        </blockquote>
      </div>

      <div ref="target" class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-elevated)] p-6 sm:p-8">
        <p class="text-label uppercase tracking-[0.06em] text-[var(--color-text-muted)] mb-6">
          Day 1 vs. Day 90
        </p>

        <dl class="space-y-5 mb-8">
          <div class="flex items-center justify-between">
            <dt class="text-body text-[var(--color-text-secondary)]">Facts</dt>
            <dd class="text-heading-2 tabular-nums text-[var(--color-text-primary)]">
              <span class="text-body text-[var(--color-text-muted)]">12 →</span> {{ facts }}
            </dd>
          </div>
          <div class="flex items-center justify-between">
            <dt class="text-body text-[var(--color-text-secondary)]">Knowledge</dt>
            <dd class="text-heading-2 tabular-nums text-[var(--color-text-primary)]">
              <span class="text-body text-[var(--color-text-muted)]">4 →</span> {{ knowledge }}
            </dd>
          </div>
          <div class="flex items-center justify-between">
            <dt class="text-body text-[var(--color-text-secondary)]">Campaigns</dt>
            <dd class="text-heading-2 tabular-nums text-[var(--color-text-primary)]">
              <span class="text-body text-[var(--color-text-muted)]">0 →</span> {{ campaigns }}
            </dd>
          </div>
        </dl>

        <div class="space-y-3">
          <div class="flex items-center justify-between text-body-sm text-[var(--color-text-muted)]">
            <span>Approval rate — Week 1</span>
          </div>
          <ScoreBar label="Approval rate, week one" :value="62" :reveal="isVisible" />

          <div class="flex items-center justify-between text-body-sm text-[var(--color-text-muted)] pt-2">
            <span>Approval rate — Day 90</span>
          </div>
          <ScoreBar label="Approval rate, day 90" :value="91" :reveal="isVisible" fill-class="bg-[var(--color-success-text)]" />
        </div>
      </div>
    </div>
  </section>
</template>
