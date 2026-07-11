<script setup lang="ts">
import {
  GlobeAltIcon,
  CpuChipIcon,
  ArrowPathIcon,
  LightBulbIcon,
  SparklesIcon,
  MegaphoneIcon,
  ShareIcon,
  PencilIcon,
  CheckIcon,
  ClipboardDocumentCheckIcon,
  ChartBarIcon,
  AcademicCapIcon,
  ArrowTrendingUpIcon,
} from '@heroicons/vue/24/outline'
import SectionHeading from './SectionHeading.vue'
import { useScrollReveal } from '@/composables/useScrollReveal'

const { target, isVisible } = useScrollReveal()

const groups = [
  {
    name: 'Business Intelligence',
    features: [
      {
        icon: GlobeAltIcon,
        name: 'Website Intelligence',
        description:
          'Atlas crawls your website continuously, extracting your catalog, active inventory, pricing signals, and marketing content. It builds structured knowledge — not a scraped snapshot, but an organized understanding of what you sell and how it changes.',
      },
      {
        icon: CpuChipIcon,
        name: 'Business Brain',
        description:
          'A persistent, living model of your business. Facts Atlas has observed, knowledge it has synthesized, patterns it has identified. The Business Brain is what makes Atlas’s recommendations specific to you rather than generic.',
      },
      {
        icon: ArrowPathIcon,
        name: 'Continuous Observation',
        description:
          'Atlas doesn’t wait to be run. It monitors your connected sources on a schedule and updates the Business Brain when things change — a new item listed, an auction opening, a page updated.',
      },
    ],
  },
  {
    name: 'Recommendation & Campaign',
    features: [
      {
        icon: LightBulbIcon,
        name: 'Opportunity Detection',
        description:
          'The Opportunity Engine scans the Business Brain for high-signal marketing moments: an auction closing in 48 hours, a new high-grade item with no promotion, an inventory gap creating a re-engagement window. It scores each opportunity and selects the best one.',
      },
      {
        icon: SparklesIcon,
        name: 'Explained Recommendations',
        description:
          'Every recommendation answers four questions before you see it: why now, why this campaign, why this channel, and why we expect it to work. This is not a tooltip or an “info” button. It is the first thing on the recommendation screen.',
      },
      {
        icon: MegaphoneIcon,
        name: 'Campaign Strategy',
        description:
          'For each opportunity, Atlas prepares a full campaign blueprint: target audience, positioning, channel selection, timing, CTA, and expected impact. The strategy is generated before the content is written, not after.',
      },
      {
        icon: ShareIcon,
        name: 'Multi-Channel Content',
        description:
          'Campaign content is generated for each selected channel: Instagram captions, Facebook posts, email subject lines and body copy, and more. Each piece is tailored to the platform’s format and your brand’s voice — not duplicated copy posted everywhere.',
      },
    ],
  },
  {
    name: 'Approval & Control',
    features: [
      {
        icon: PencilIcon,
        name: 'Inline Editing',
        description:
          'Every piece of content Atlas prepares is editable before you approve it. Change a sentence, adjust the tone, replace a detail — the system records what you changed and uses it to write better content next time.',
      },
      {
        icon: CheckIcon,
        name: 'Approval Workflow',
        description:
          'Three actions on every recommendation: Approve, Edit & Approve, Not This Time. Approve means the content is queued for publishing exactly as written. Edit & Approve means you adjust it first. Not This Time means Atlas learns why and surfaces a new recommendation when conditions are right.',
      },
      {
        icon: ClipboardDocumentCheckIcon,
        name: 'Audit Trail',
        description:
          'Every action is permanently recorded: who approved, what was changed, when, and why. If a client asks what went out and why, the record is complete.',
      },
    ],
  },
  {
    name: 'Learning & Analytics',
    features: [
      {
        icon: ChartBarIcon,
        name: 'Campaign Analytics',
        description:
          'After each campaign, Atlas compares actual reach and engagement against what it predicted. The comparison is always visible: expected vs actual, side by side.',
      },
      {
        icon: AcademicCapIcon,
        name: 'Learning Engine',
        description:
          'Atlas accumulates learning from every approval, edit, rejection, and campaign outcome. Over time, it adjusts its channel preferences, content style, and opportunity selection to match what has actually worked for your business.',
      },
      {
        icon: ArrowTrendingUpIcon,
        name: 'Approval Rate Trend',
        description:
          'Sofia can show a client a chart of Atlas’s approval rate over 90 days. If it’s rising — and it should be — that is measurable evidence that the system is learning and the recommendations are improving.',
      },
    ],
  },
]
</script>

<template>
  <section class="py-20 sm:py-28 px-4 sm:px-8">
    <div class="mx-auto max-w-[1280px]">
      <SectionHeading eyebrow="What Atlas does" align="center" class="mb-14">
        Every part of the marketing loop, handled.
      </SectionHeading>

      <div ref="target" class="space-y-14">
        <div v-for="group in groups" :key="group.name">
          <h3 class="text-heading-3 text-[var(--color-text-muted)] uppercase tracking-[0.06em] mb-6">
            {{ group.name }}
          </h3>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <div
              v-for="(feature, index) in group.features"
              :key="feature.name"
              class="transition-all duration-300 ease-[var(--ease-out)]"
              :class="isVisible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'"
              :style="{ transitionDelay: isVisible ? `${index * 50}ms` : '0ms' }"
            >
              <component :is="feature.icon" class="size-6 text-[var(--color-accent-500)] mb-3" aria-hidden="true" />
              <h4 class="text-heading-3 text-[var(--color-text-primary)] mb-2">{{ feature.name }}</h4>
              <p class="text-body text-[var(--color-text-secondary)]">{{ feature.description }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
