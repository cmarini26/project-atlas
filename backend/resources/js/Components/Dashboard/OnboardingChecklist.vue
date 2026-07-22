<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { LightBulbIcon, CpuChipIcon, MegaphoneIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const dismissing = ref(false)

function dismiss(): void {
  if (dismissing.value) return
  dismissing.value = true
  router.post('/checklist/dismiss', {}, { preserveScroll: true, onFinish: () => { dismissing.value = false } })
}

const items = [
  {
    icon: LightBulbIcon,
    title: 'Review your first recommendation',
    description: 'See what Atlas suggests and why — approve it, edit it, or pass.',
    href: '/app/recommendations',
  },
  {
    icon: CpuChipIcon,
    title: 'Explore your Business Brain',
    description: 'See the facts and knowledge Atlas has learned about your business so far.',
    href: '/app/brain',
  },
  {
    icon: MegaphoneIcon,
    title: 'Review your marketing presence',
    description: 'Confirm the channels Atlas knows about, and add any it\'s missing.',
    href: '/app/settings/marketing-presence',
  },
]
</script>

<template>
  <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-[var(--radius-md)] shadow-[var(--shadow-card)] mb-6 overflow-hidden">
    <div class="flex items-start justify-between gap-3 px-5 py-4 bg-[var(--color-surface-panel)] border-b border-[var(--color-border)]">
      <div>
        <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">3 things to do first</h2>
        <p class="text-xs text-[var(--color-text-muted)] mt-0.5">A quick orientation — dismiss this whenever you're ready.</p>
      </div>
      <button
        type="button"
        :disabled="dismissing"
        class="shrink-0 text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)] disabled:opacity-60"
        aria-label="Dismiss checklist"
        @click="dismiss"
      >
        <XMarkIcon class="size-5" />
      </button>
    </div>

    <div class="grid gap-0 divide-y divide-[var(--color-border)] lg:grid-cols-3 lg:divide-x lg:divide-y-0">
      <Link
        v-for="item in items"
        :key="item.href"
        :href="item.href"
        class="flex items-start gap-3 p-5 hover:bg-[var(--color-surface-panel)] transition-colors duration-[var(--duration-fast)]"
      >
        <div class="size-9 shrink-0 rounded-[var(--radius-sm)] bg-[var(--color-accent-50)] text-[var(--color-accent-700)] ring-1 ring-[var(--color-accent-200)] flex items-center justify-center">
          <component :is="item.icon" class="size-4" />
        </div>
        <div class="min-w-0">
          <p class="text-sm font-semibold text-[var(--color-text-primary)]">{{ item.title }}</p>
          <p class="mt-1 text-xs leading-5 text-[var(--color-text-muted)]">{{ item.description }}</p>
        </div>
      </Link>
    </div>
  </div>
</template>
