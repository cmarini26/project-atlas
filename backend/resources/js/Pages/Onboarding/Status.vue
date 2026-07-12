<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { router, Head } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'

interface StatusData {
  twin_status: string | null
  integration_status: string | null
  sync_started: boolean
  crawl_succeeded: boolean
  pipeline_stalled: boolean
  ai_failed: boolean
  ai_retrying: boolean
  no_opportunities: boolean
  fact_count: number
  opportunity_count: number
  recommendation_count: number
  first_recommendation_id: string | null
}

const status = ref<StatusData>({
  twin_status: 'initializing',
  integration_status: 'active',
  sync_started: false,
  crawl_succeeded: false,
  pipeline_stalled: false,
  ai_failed: false,
  ai_retrying: false,
  no_opportunities: false,
  fact_count: 0,
  opportunity_count: 0,
  recommendation_count: 0,
  first_recommendation_id: null,
})

const loading = ref(true)
const retrying = ref(false)
const startTime = Date.now()
let intervalId: ReturnType<typeof setInterval> | null = null

function retry(): void {
  if (retrying.value) return
  retrying.value = true
  router.post('/onboarding/retry', {}, { onFinish: () => { retrying.value = false } })
}

const isTimedOut = computed(() => Date.now() - startTime > 5 * 60 * 1000)
const isFailed = computed(() => status.value.integration_status === 'error')
const isAiFailed = computed(() => status.value.ai_failed)
const isAiRetrying = computed(() => status.value.ai_retrying && !isFailed.value && !isAiFailed.value)
const isStalled = computed(() => status.value.pipeline_stalled && !isFailed.value && !isAiFailed.value && !isAiRetrying.value)
const isNoOpportunities = computed(() => status.value.no_opportunities && !isFailed.value && !isAiFailed.value && !isAiRetrying.value && !isStalled.value)

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

      if (isFailed.value || isAiFailed.value || isNoOpportunities.value) {
        if (intervalId) clearInterval(intervalId)
        return
      }

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
      <!-- Sync failed -->
      <div v-if="isFailed">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-red-50 flex items-center justify-center">
            <svg class="size-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
          </div>
        </div>
        <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-2">Atlas couldn't reach your website</h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-6">This is often temporary — your site may have been briefly unreachable. You can retry the same URL, or try a different one if the problem persists.</p>
        <div class="flex items-center justify-center gap-3">
          <button
            type="button"
            :disabled="retrying"
            class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
            @click="retry"
          >
            {{ retrying ? 'Retrying…' : 'Retry' }}
          </button>
          <a
            href="/onboarding"
            class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-raised)] transition-colors duration-[var(--duration-fast)]"
          >
            Try a different URL
          </a>
        </div>
      </div>

      <!-- AI analysis failed -->
      <div v-else-if="isAiFailed">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-red-50 flex items-center justify-center">
            <svg class="size-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
          </div>
        </div>
        <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-2">AI analysis encountered an error</h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-3">Your website was scanned successfully, but Atlas couldn't extract usable business facts from it. This can happen when the AI provider is misconfigured, or when the page doesn't contain enough readable text about your business.</p>
        <p class="text-xs text-[var(--color-text-muted)] mb-6">Check that <code class="font-mono bg-[var(--color-surface-raised)] px-1 rounded">ANTHROPIC_API_KEY</code> is set correctly in <code class="font-mono bg-[var(--color-surface-raised)] px-1 rounded">.env</code>, or try a page with more content about your business (like an About page).</p>
        <div class="flex items-center justify-center gap-3">
          <button
            type="button"
            :disabled="retrying"
            class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
            @click="retry"
          >
            {{ retrying ? 'Retrying…' : 'Retry' }}
          </button>
          <a
            href="/onboarding"
            class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-raised)] transition-colors duration-[var(--duration-fast)]"
          >
            Try a different URL
          </a>
          <a
            href="/app"
            class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-raised)] transition-colors duration-[var(--duration-fast)]"
          >
            Go to dashboard
          </a>
        </div>
      </div>

      <!-- AI provider temporarily overloaded — retrying automatically -->
      <div v-else-if="isAiRetrying">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-amber-50 flex items-center justify-center">
            <svg class="size-6 text-amber-500 animate-spin" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
          </div>
        </div>
        <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-2">Atlas is waiting for the AI provider</h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-3">Your website was scanned successfully, but the AI provider is temporarily overloaded. Atlas will retry automatically — no action needed.</p>
        <p class="text-xs text-[var(--color-text-muted)] mb-6">You can stay on this page or come back later. Analysis will resume as soon as the provider is available.</p>
        <a
          href="/app"
          class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-raised)] transition-colors duration-[var(--duration-fast)]"
        >
          Go to dashboard
        </a>
      </div>

      <!-- Pipeline stalled — queue worker not processing jobs -->
      <div v-else-if="isStalled">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-amber-50 flex items-center justify-center">
            <svg class="size-6 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
          </div>
        </div>
        <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-2">Atlas is waiting for a queue worker</h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-3">Your analysis is queued, but no worker is processing jobs. Start the dev stack with <code class="font-mono bg-[var(--color-surface-raised)] px-1 rounded">composer dev</code>, or run a worker directly:</p>
        <p class="text-xs text-[var(--color-text-muted)] font-mono bg-[var(--color-surface-raised)] rounded px-3 py-2 mb-6 text-left">php artisan queue:work --queue=high,ai,default,observations,publishing,analytics,maintenance</p>
        <a
          href="/app"
          class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] transition-colors duration-[var(--duration-fast)]"
        >
          Go to dashboard
        </a>
      </div>

      <!-- Facts learned, but no campaign opportunity found (legitimate outcome) -->
      <div v-else-if="isNoOpportunities">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-[var(--color-accent-50)] flex items-center justify-center">
            <svg class="size-6 text-[var(--color-accent-600)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
          </div>
        </div>
        <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-2">Atlas learned your business — no campaign opportunity yet</h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-3">
          Atlas gathered {{ status.fact_count }} facts about your business, but didn't find a strong enough campaign opportunity from this first scan.
        </p>
        <p class="text-xs text-[var(--color-text-muted)] mb-6">
          Next steps: review what Atlas learned in the Business Brain. Atlas re-scans your website automatically and will surface a recommendation as soon as it finds a strong opportunity.
        </p>
        <div class="flex items-center justify-center gap-3">
          <a
            href="/app"
            class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] transition-colors duration-[var(--duration-fast)]"
          >
            Go to dashboard
          </a>
          <a
            href="/app/brain"
            class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-raised)] transition-colors duration-[var(--duration-fast)]"
          >
            See what Atlas learned
          </a>
        </div>
      </div>

      <!-- Timeout message (no error, but no recommendation after 5 min) -->
      <div v-else-if="isTimedOut && status.recommendation_count === 0">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-[var(--color-accent-50)] flex items-center justify-center">
            <svg class="size-6 text-[var(--color-accent-600)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
          </div>
        </div>
        <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-2">This is taking a moment</h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-6">Atlas is doing a thorough analysis. You can leave this page — your first recommendation will be waiting on the dashboard when it's ready.</p>
        <a
          href="/app"
          class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] transition-colors duration-[var(--duration-fast)]"
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
          {{ loading ? 'Checking in with Atlas…' : (stepLabels[status.twin_status ?? ''] ?? 'Processing…') }}
        </h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-6">This usually takes a few minutes. You can leave and come back.</p>

        <!-- Progress items -->
        <div class="space-y-3 text-left" aria-live="polite">
          <div class="flex items-center gap-3">
            <div :class="['size-5 rounded-full flex items-center justify-center shrink-0', status.sync_started ? 'bg-[var(--color-accent-500)]' : 'bg-[var(--color-border)]']">
              <svg v-if="status.sync_started" class="size-3 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            </div>
            <span class="text-sm text-[var(--color-text-secondary)]">Website scanned</span>
          </div>

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
