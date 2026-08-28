<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { LinkIcon, XMarkIcon } from '@heroicons/vue/24/outline'

export interface PendingChannel {
  type: string
  label: string
  requirement: 'handle' | 'oauth'
  summary: string
}

defineProps<{ channels: PendingChannel[] }>()

const dismissing = ref(false)

function dismiss(): void {
  if (dismissing.value) return
  dismissing.value = true
  router.post('/channel-setup/dismiss', {}, { preserveScroll: true, onFinish: () => { dismissing.value = false } })
}
</script>

<template>
  <div class="mb-6 overflow-hidden rounded-[var(--radius-md)] border border-amber-200 bg-amber-50 shadow-[var(--shadow-card)]">
    <div class="flex items-start justify-between gap-3 border-b border-amber-200 px-5 py-4">
      <div>
        <h2 class="text-sm font-semibold text-amber-900">Finish connecting your channels</h2>
        <p class="mt-0.5 text-xs text-amber-800">
          You selected these during onboarding, but they still need to be connected before Atlas can use them.
        </p>
      </div>
      <button
        type="button"
        :disabled="dismissing"
        class="shrink-0 text-amber-700 hover:text-amber-900 disabled:opacity-60"
        aria-label="Dismiss channel setup reminder"
        @click="dismiss"
      >
        <XMarkIcon class="size-5" />
      </button>
    </div>

    <ul class="divide-y divide-amber-200">
      <li v-for="channel in channels" :key="channel.type" class="flex items-center justify-between gap-4 px-5 py-3">
        <div class="min-w-0">
          <p class="text-sm font-semibold text-amber-900">{{ channel.label }}</p>
          <p class="mt-0.5 text-xs text-amber-800">{{ channel.summary }}</p>
        </div>
        <Link
          href="/app/settings/marketing-presence"
          class="inline-flex shrink-0 items-center gap-1.5 rounded-[var(--radius-sm)] border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100"
        >
          <LinkIcon class="size-3.5" />
          Connect
        </Link>
      </li>
    </ul>
  </div>
</template>
