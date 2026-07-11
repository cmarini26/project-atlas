<script setup lang="ts">
import { CheckCircleIcon } from '@heroicons/vue/24/outline'
import SectionHeading from './SectionHeading.vue'
import RecommendationMockup from './RecommendationMockup.vue'

const quadrants = [
  {
    label: 'Why now?',
    body: '12 auctions close in the next 48 hours. This is your peak bid-driving window. Urgency posts outside this window don’t perform.',
  },
  {
    label: 'Why this?',
    body: 'Your Action Comics #1 CGC 6.0 is your highest-scoring item this month (94/100). It hasn’t been featured in 23 days and the auction closes Sunday at 9 PM EST.',
  },
  {
    label: 'Why this channel?',
    body: 'Your last 3 Instagram posts generated 2.1× your 90-day average reach. Email has underperformed for this audience consistently. Instagram first.',
  },
  {
    label: 'Why it will work?',
    body: 'Urgency-framed posts for closing auctions have produced 2–3× click-through in your last four comparable runs. Expected reach: 2,400–3,200 accounts.',
  },
]

const callouts = [
  'Not a template — specific to your catalog, right now.',
  'Four questions, always answered, before you’re asked to approve.',
  'One click to approve. Nothing published without it.',
]
</script>

<template>
  <section id="recommendation-showcase" class="py-20 sm:py-28 px-4 sm:px-8">
    <div class="mx-auto max-w-[1280px]">
      <SectionHeading eyebrow="What a recommendation looks like" align="center">
        Atlas doesn&rsquo;t ask you to make decisions.<br />It presents conclusions for your review.
      </SectionHeading>

      <p class="mt-6 max-w-[720px] mx-auto text-body-lg text-[var(--color-text-secondary)] text-center">
        Every recommendation Atlas surfaces has already been through a full analysis: what the
        opportunity is, why the timing is right, which channel fits your audience, and what
        outcome to expect. You&rsquo;re not being asked to figure any of this out. You&rsquo;re being
        asked to confirm that Atlas got it right.
      </p>

      <div class="mt-12 grid grid-cols-1 lg:grid-cols-[1fr_260px] gap-8 items-start">
        <RecommendationMockup
          title="Featured auction promotion"
          channels="Instagram · Facebook"
          :quadrants="quadrants"
          :confidence="84"
          variant="full"
          draft-label="Draft content — Instagram"
          draft-text="12 auctions closing Sunday — including a CGC 6.0 Action Comics #1 that hasn't hit the block in over two decades. Link in bio to browse the full catalog before 9 PM EST."
          caption="Full recommendation card for a featured auction promotion on Instagram and Facebook, showing four rationale quadrants, an 84 percent confidence score, and a draft Instagram caption."
        />

        <ul class="space-y-4 lg:pt-6">
          <li v-for="callout in callouts" :key="callout" class="flex gap-2 text-body text-[var(--color-text-secondary)]">
            <CheckCircleIcon class="size-5 shrink-0 text-[var(--color-accent-500)]" aria-hidden="true" />
            <span>{{ callout }}</span>
          </li>
        </ul>
      </div>

      <p class="mt-10 max-w-[720px] mx-auto text-body-lg text-[var(--color-text-secondary)] text-center">
        The content you see is not a starting point for you to finish. It&rsquo;s a first draft by
        a system that already knows your catalog, your channel history, and your audience. If
        it&rsquo;s right, approve it. If you&rsquo;d change the tone, the channel, or a single
        sentence — edit it inline and approve what you actually want to publish.
      </p>
      <p class="mt-4 text-heading-3 text-[var(--color-text-primary)] text-center">
        Either way, Atlas learns from what you did.
      </p>
    </div>
  </section>
</template>
