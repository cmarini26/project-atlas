<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { ChevronDownIcon } from '@heroicons/vue/24/outline'
import SectionHeading from './SectionHeading.vue'

interface Faq {
  question: string
  answer: string
}

const faqs: Faq[] = [
  {
    question: 'Is this just another AI writing tool?',
    answer:
      'No. AI writing tools wait for you to describe what you want and generate content on demand. Atlas observes your business, decides what campaign is worth running right now, and prepares the content before you ask. The product is the recommendation and the rationale — not a blank box you type into.',
  },
  {
    question: 'How does Atlas know what to promote?',
    answer:
      'Atlas crawls your connected website and builds a structured model of your catalog and marketing signals. It tracks which items are getting attention, what’s selling, what’s expiring, and what you’ve promoted before. The Opportunity Engine scores your current inventory and identifies the highest-priority marketing moment — an auction closing, a new arrival, a re-engagement window — and recommends a campaign for it.',
  },
  {
    question: 'What happens when I approve a recommendation?',
    answer:
      'Atlas marks the campaign as approved and queues the content for publishing. It moves into your Campaigns list, where you can track its status. The content that publishes is exactly what you approved — or what you edited before approving.',
  },
  {
    question: 'Can I edit the content before it publishes?',
    answer:
      'Yes. Every recommendation includes an “Edit & Approve” option. You can change any piece of the generated content inline before approving. What you approve is what publishes. Atlas records what you changed and uses it to improve future content for your business.',
  },
  {
    question: 'What if the recommendation isn’t right for my business right now?',
    answer:
      'Click “Not this time.” Atlas will ask you for an optional note explaining why — timing, channel, content — and learns from it. You won’t see the same recommendation again. Atlas will surface a new one when conditions change. Passing on a recommendation is not a failure. It is how Atlas gets smarter.',
  },
  {
    question: 'Will Atlas ever publish something without my approval?',
    answer:
      'No. This is not a setting you can turn on or off — it is structural. An approval must exist in the system before any content is scheduled for publishing. We do not offer autonomous publishing. We believe approval is the product, not a temporary safety measure.',
  },
  {
    question: 'How long does setup take?',
    answer:
      'The initial setup — entering your business name, industry, and website URL — takes under five minutes. Once you submit your website, Atlas begins crawling immediately. Your first recommendation typically appears within minutes of connecting your site, depending on catalog size.',
  },
  {
    question: 'Does Atlas work for my type of business?',
    answer:
      'Atlas is designed for businesses with dynamic inventory, recurring marketing moments, and limited time to manage them manually. Auction houses, car dealers, specialty retailers, and service businesses have all been part of the design process. If you are unsure, connect your website and see what Atlas makes of it — it’s free to start.',
  },
  {
    question: 'What does Atlas need access to?',
    answer:
      'Atlas needs your public-facing website URL. It crawls the pages, extracts catalog and product information, and builds the Business Brain from what it finds. It does not need access to your backend systems, payment data, or internal databases. Your website’s public content is sufficient.',
  },
  {
    question: 'What happens to my data if I cancel?',
    answer:
      'Your data is retained for 30 days after cancellation to allow for export. After 30 days, it is permanently deleted from Atlas’s systems. We do not sell or share business data under any circumstances.',
  },
]

const openIndex = ref<number | null>(null)
const panelRefs = ref<Array<HTMLElement | null>>([])

async function toggle(index: number): Promise<void> {
  const wasOpen = openIndex.value === index
  openIndex.value = wasOpen ? null : index

  if (!wasOpen) {
    await nextTick()
    panelRefs.value[index]?.focus()
  }
}
</script>

<template>
  <section class="py-20 sm:py-28 px-4 sm:px-8">
    <div class="mx-auto max-w-[720px]">
      <SectionHeading eyebrow="Questions" align="center" class="mb-12">
        Before you ask.
      </SectionHeading>

      <div class="divide-y divide-[var(--color-border)] border-y border-[var(--color-border)]">
        <div v-for="(faq, index) in faqs" :key="faq.question">
          <h3>
            <button
              type="button"
              class="w-full flex items-center justify-between gap-4 py-5 text-left"
              :aria-expanded="openIndex === index"
              :aria-controls="`faq-panel-${index}`"
              @click="toggle(index)"
            >
              <span class="text-heading-3 text-[var(--color-text-primary)]">{{ faq.question }}</span>
              <ChevronDownIcon
                class="size-5 shrink-0 text-[var(--color-text-muted)] transition-transform duration-150"
                :class="{ 'rotate-180': openIndex === index }"
                aria-hidden="true"
              />
            </button>
          </h3>
          <div
            :id="`faq-panel-${index}`"
            :ref="(el) => (panelRefs[index] = el as HTMLElement)"
            tabindex="-1"
            :aria-hidden="openIndex !== index"
            class="overflow-hidden transition-all duration-200 ease-[var(--ease-out)]"
            :class="openIndex === index ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'"
            style="display: grid"
          >
            <div class="min-h-0 overflow-hidden">
              <p class="pb-5 text-body-lg text-[var(--color-text-secondary)]">{{ faq.answer }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
