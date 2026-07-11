<script setup lang="ts">
import { onMounted, ref } from 'vue'
import MarketingButton from './MarketingButton.vue'
import RecommendationMockup from './RecommendationMockup.vue'

const hasEntered = ref(false)

onMounted(() => {
  hasEntered.value = true
})

const quadrants = [
  { label: 'Why now?', body: '12 auctions close in 48h. This window expires Sunday.' },
  { label: 'Why this?', body: 'Your Silver Age lot scores 94/100 — highest in 60 days.' },
  { label: 'Why this channel?', body: 'Your last 3 Instagram posts drove 2× your average reach.' },
  { label: 'Why it will work?', body: 'Urgency posts for closing auctions have driven 2–3× normal engagement in your last four campaigns.' },
]
</script>

<template>
  <section class="relative pt-32 pb-16 sm:pt-40 sm:pb-24 px-4 sm:px-8 overflow-hidden">
    <div class="mx-auto max-w-[1280px] grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16 items-center">
      <div
        class="text-left transition-opacity duration-300"
        :class="hasEntered ? 'opacity-100' : 'opacity-0'"
      >
        <p class="text-label uppercase tracking-[0.06em] text-[var(--color-text-muted)] mb-4">
          AI Marketing for Independent Businesses
        </p>

        <h1 class="text-display sm:text-[2.75rem] sm:leading-[1.15] text-[var(--color-text-primary)] mb-6 text-balance">
          Marketing that arrives<br />before you think to ask for it.
        </h1>

        <p class="text-body-lg text-[var(--color-text-secondary)] max-w-xl mb-8">
          Atlas connects to your business, builds a living model of what you sell, and recommends
          the right campaign at the right moment — with a full explanation of why. You review it.
          You approve it. Atlas handles the rest.
        </p>

        <div
          class="flex flex-col sm:flex-row gap-4 transition-all duration-300"
          :class="hasEntered ? 'opacity-100 translate-y-0 delay-200' : 'opacity-0 translate-y-2'"
        >
          <MarketingButton href="/register" variant="primary" size="lg">
            Connect Your Business — It&rsquo;s Free
          </MarketingButton>
          <MarketingButton href="#recommendation-showcase" variant="ghost" size="lg">
            See a Real Recommendation
          </MarketingButton>
        </div>

        <p class="mt-6 text-body-sm text-[var(--color-text-muted)]">
          &#9642; Built for independent auction houses, dealerships, and boutique retailers
        </p>
      </div>

      <div
        class="transition-all duration-[400ms] ease-[var(--ease-out)]"
        :class="hasEntered ? 'opacity-100 translate-y-0 delay-100' : 'opacity-0 translate-y-2'"
      >
        <div class="lg:rotate-[3deg]">
          <RecommendationMockup
            title="Featured auction promotion"
            :quadrants="quadrants"
            :confidence="84"
            variant="compact"
            caption="Recommendation card showing four rationale quadrants — why now, why this, why this channel, and why it will work — for a comic book auction promotion, with an 84 percent confidence score."
          />
        </div>
      </div>
    </div>
  </section>
</template>
