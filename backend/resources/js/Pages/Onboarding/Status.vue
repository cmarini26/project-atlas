<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { router, Head } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'

interface StatusData {
  twin_status: string
  fact_count: number
  opportunity_count: number
  recommendation_count: number
  first_recommendation_id: string | null
}

const status = ref<StatusData>({
  twin_status: 'initializing',
  fact_count: 0,
  opportunity_count: 0,
  recommendation_count: 0,
  first_recommendation_id: null,
})

const loading = ref(true)
const startTime = Date.now()
let intervalId: ReturnType<typeof setInterval> | null = null

const isTimedOut = computed(() => Date.now() - startTime > 5 * 60 * 1000)

async function fetchStatus(): Promise<void> {
  if (Date.now() - startTime > 10 * 60 * 1000) {
    if (intervalId) clearInterval(intervalId)
    return
  }

  try {
    const response = await fetch('/api/onboarding/status', {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (response.ok) {
      status.value = await response.json() as StatusData
      loading.value = false

      if (status.value.recommendation_count > 0) {
        if (intervalId) clearInterval(intervalId)
        const destination = status.value.first_recommendation_id
          ? `/app/recommendations/${status.value.first_recommendation_id}`
          : '/app'
        router.visit(destination)
      }
    }
  } catch {
    // silently retry
  }
}

const stepLabels: Record<string, string> = {
  initializing: 'Getting started…',
  active: 'Building your first recommendation…',
}

onMounted(() => {
  void fetchStatus()
  intervalId = setInterval(() => { void fetchStatus() }, 5000)
})

onUnmounted(() => {
  if (intervalId) clearInterval(intervalId)
})
</script>

<template>
  <Head><title>Setting up — Atlas</title></Head>
  <AuthLayout>
    <div class="text-center">
      <!-- Timeout message -->
      <div v-if="isTimedOut && status.recommendation_count === 0">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-[var(--color-accent-50)] flex items-center justify-center">
            <svg class="size-6 text-[var(--color-accent-600)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
          </div>
        </div>
        <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-2">This is taking a moment</h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-6">Atlas is doing a thorough analysis. You can leave this page — we'll notify you when the first recommendation is ready.</p>
        <a
          href="/app"
          class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg bg-[var(--color-accent-600)] text-white hover:bg-[var(--color-accent-700)] transition-colors duration-[var(--duration-fast)]"
        >
          Go to dashboard
        </a>
      </div>

      <!-- Normal loading state -->
      <template v-else>
        <!-- Spinner -->
        <div class="flex items-center justify-center mb-5">
          <div class="size-12 rounded-full border-2 border-[var(--color-border)] border-t-[var(--color-accent-500)] animate-spin" aria-hidden="true" />
        </div>

        <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1">
          {{ loading ? 'Checking in with Atlas…' : (stepLabels[status.twin_status] ?? 'Processing…') }}
        </h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-6">This usually takes a few minutes. You can leave and come back.</p>

        <!-- Progress items -->
        <div class="space-y-3 text-left" aria-live="polite">
          <div class="flex items-center gap-3">
            <div :class="['size-5 rounded-full flex items-center justify-center shrink-0', status.fact_count > 0 ? 'bg-[var(--color-accent-500)]' : 'bg-[var(--color-border)]']">
              <svg v-if="status.fact_count > 0" class="size-3 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            </div>
            <span class="text-sm text-[var(--color-text-secondary)]">
              Facts gathered
              <span v-if="status.fact_count > 0" class="text-[var(--color-text-muted)]">({{ status.fact_count }})</span>
            </span>
          </div>

          <div class="flex items-center gap-3">
            <div :class="['size-5 rounded-full flex items-center justify-center shrink-0', status.opportunity_count > 0 ? 'bg-[var(--color-accent-500)]' : 'bg-[var(--color-border)]']">
              <svg v-if="status.opportunity_count > 0" class="size-3 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            </div>
            <span class="text-sm text-[var(--color-text-secondary)]">
              Opportunities identified
              <span v-if="status.opportunity_count > 0" class="text-[var(--color-text-muted)]">({{ status.opportunity_count }})</span>
            </span>
          </div>

          <div class="flex items-center gap-3">
            <div :class="['size-5 rounded-full flex items-center justify-center shrink-0', status.recommendation_count > 0 ? 'bg-[var(--color-accent-500)]' : 'bg-[var(--color-border)]']">
              <svg v-if="status.recommendation_count > 0" class="size-3 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            </div>
            <span class="text-sm text-[var(--color-text-secondary)]">First recommendation ready</span>
          </div>
        </div>

        <a
          href="/app"
          class="mt-6 inline-block text-sm text-[var(--color-text-link)] hover:underline"
        >
          Skip to dashboard
        </a>
      </template>
    </div>
  </AuthLayout>
</template>
