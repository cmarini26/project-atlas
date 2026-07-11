<script setup lang="ts">
import {
  LockClosedIcon,
  ShieldCheckIcon,
  ClipboardDocumentListIcon,
  LinkSlashIcon,
  NoSymbolIcon,
  KeyIcon,
} from '@heroicons/vue/24/outline'
import SectionHeading from './SectionHeading.vue'
import { useScrollReveal } from '@/composables/useScrollReveal'

const { target, isVisible } = useScrollReveal()

const items = [
  {
    icon: LockClosedIcon,
    heading: 'Your data stays yours',
    body: 'Atlas reads your website to build the Business Brain. It does not sell that data, share it with other businesses, or use it to train models for other customers. Your catalog and your audience signals belong to your company.',
  },
  {
    icon: ShieldCheckIcon,
    heading: 'Nothing publishes without approval',
    body: 'This is enforced at the system level, not just the UI. An approval record must exist in the database before any content is scheduled for publishing. Removing the approval step is not possible — it is structural.',
  },
  {
    icon: ClipboardDocumentListIcon,
    heading: 'Every action is recorded',
    body: 'Who approved, who edited, who passed, and when — every action is permanently logged. If something goes out that shouldn’t have, the audit trail is complete and immediate.',
  },
  {
    icon: LinkSlashIcon,
    heading: 'One-click disconnection',
    body: 'If you disconnect an integration, Atlas stops crawling immediately. No pending jobs complete after disconnection. Your data is not retained beyond the period you specify.',
  },
  {
    icon: NoSymbolIcon,
    heading: 'No access to financial data',
    body: 'Atlas reads your marketing-facing website and catalog. It does not have access to your accounting, payment processing, inventory management system, or any internal data that isn’t intentionally public-facing.',
  },
  {
    icon: KeyIcon,
    heading: 'Security practices',
    body: 'All credentials are encrypted at rest. Channel publishing credentials are stored using field-level encryption. No credentials are logged or visible in Atlas’s admin interface.',
  },
]
</script>

<template>
  <section class="py-20 sm:py-28 px-4 sm:px-8 bg-[var(--color-surface-subtle)]">
    <div class="mx-auto max-w-[1280px]">
      <SectionHeading eyebrow="Data and trust" align="center">
        Atlas is a trusted system, not a surveillance tool.
      </SectionHeading>
      <p class="mt-6 max-w-[720px] mx-auto text-body-lg text-[var(--color-text-secondary)] text-center">
        You are connecting your business&rsquo;s catalog and marketing data to Atlas. Here is
        exactly what that means.
      </p>

      <div ref="target" class="mt-12 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
        <div
          v-for="(item, index) in items"
          :key="item.heading"
          class="transition-all duration-300 ease-[var(--ease-out)]"
          :class="isVisible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'"
          :style="{ transitionDelay: isVisible ? `${index * 50}ms` : '0ms' }"
        >
          <component :is="item.icon" class="size-6 text-[var(--color-accent-500)] mb-3" aria-hidden="true" />
          <h3 class="text-heading-3 text-[var(--color-text-primary)] mb-2">{{ item.heading }}</h3>
          <p class="text-body text-[var(--color-text-secondary)]">{{ item.body }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
