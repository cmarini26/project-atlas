<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { router, Head } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'

interface ConnectorStatus {
  type: string
  label: string
  status: 'pending' | 'running' | 'succeeded' | 'failed' | 'skipped_no_credentials' | 'not_attempted'
  error_message: string | null
}

interface DiscoveryProgress {
  stage: 'discovering' | 'analyzing' | 'understanding' | 'recommending' | 'completed' | 'completed_with_errors' | 'completed_no_opportunities' | null
  started_at: string | null
  completed_at: string | null
  connectors: ConnectorStatus[]
  facts_created: number
  opportunities_found: number
  recommendations_generated: number
  recommendation_count: number
  first_recommendation_id: string | null
  retry_available: boolean
}

const STAGES = ['discovering', 'analyzing', 'understanding', 'recommending'] as const
const STAGE_LABELS: Record<(typeof STAGES)[number], string> = {
  discovering: 'Discover',
  analyzing: 'Analyze',
  understanding: 'Understand',
  recommending: 'Recommend',
}

const TERMINAL_STAGES = ['completed', 'completed_with_errors', 'completed_no_opportunities']

const progress = ref<DiscoveryProgress>({
  stage: null,
  started_at: null,
  completed_at: null,
  connectors: [],
  facts_created: 0,
  opportunities_found: 0,
  recommendations_generated: 0,
  recommendation_count: 0,
  first_recommendation_id: null,
  retry_available: false,
})

const loading = ref(true)
const retrying = ref(false)
const startTime = Date.now()
let intervalId: ReturnType<typeof setInterval> | null = null

const isTimedOut = computed(() => Date.now() - startTime > 5 * 60 * 1000)
const isCompletedWithErrors = computed(() => progress.value.stage === 'completed_with_errors')
const isNoOpportunities = computed(() => progress.value.stage === 'completed_no_opportunities')
const isCompleted = computed(() => progress.value.stage === 'completed')

/** Index of the current stage among the four coarse stages — 4 once terminal. */
const currentStageIndex = computed((): number => {
  const stage = progress.value.stage
  if (stage !== null && TERMINAL_STAGES.includes(stage)) return STAGES.length
  const index = STAGES.indexOf(stage as (typeof STAGES)[number])
  return index === -1 ? 0 : index
})

function stageState(index: number): 'done' | 'active' | 'pending' {
  const isTerminal = progress.value.stage !== null && TERMINAL_STAGES.includes(progress.value.stage)
  if (index < currentStageIndex.value) return 'done'
  if (index === currentStageIndex.value && !isTerminal) return 'active'
  if (index === currentStageIndex.value) return 'done'
  return 'pending'
}

const connectorIcon: Record<ConnectorStatus['status'], string> = {
  succeeded: '✓',
  failed: '✕',
  running: '…',
  pending: '…',
  skipped_no_credentials: '○',
  not_attempted: '○',
}

function connectorNote(connector: ConnectorStatus): string | null {
  if (connector.status === 'not_attempted') return 'not connected'
  if (connector.status === 'skipped_no_credentials') return 'needs connection'
  if (connector.status === 'failed') return connector.error_message ?? 'failed'
  return null
}

const succeededConnectors = computed(() => progress.value.connectors.filter((c) => c.status === 'succeeded'))

const recommendationHref = computed(() =>
  progress.value.first_recommendation_id ? `/app/recommendations/${progress.value.first_recommendation_id}` : '/app',
)

function ensurePolling(): void {
  if (intervalId === null) {
    intervalId = setInterval(() => { void fetchStatus() }, 5000)
  }
}

function retryDiscovery(): void {
  retrying.value = true
  router.post('/onboarding/discovery/retry', {}, {
    onSuccess: () => {
      retrying.value = false
      ensurePolling()
      void fetchStatus()
    },
    onError: () => { retrying.value = false },
  })
}

async function fetchStatus(): Promise<void> {
  if (Date.now() - startTime > 10 * 60 * 1000) {
    if (intervalId) clearInterval(intervalId)
    intervalId = null
    return
  }

  try {
    const response = await fetch('/api/onboarding/status', {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (response.ok) {
      progress.value = await response.json() as DiscoveryProgress
      loading.value = false

      // A pending recommendation exists — take the user straight to it
      // rather than leaving them reading a summary screen.
      if (progress.value.recommendation_count > 0 && progress.value.first_recommendation_id) {
        if (intervalId) clearInterval(intervalId)
        intervalId = null
        router.visit(recommendationHref.value)
        return
      }

      // Terminal, no recommendation to redirect to — stop polling and show
      // the truthful outcome (completed_with_errors / no_opportunities).
      // Never leaves the user on an indefinite progress page.
      if (progress.value.stage !== null && TERMINAL_STAGES.includes(progress.value.stage)) {
        if (intervalId) clearInterval(intervalId)
        intervalId = null
      }
    }
  } catch {
    // silently retry
  }
}

onMounted(() => {
  void fetchStatus()
  ensurePolling()
})

onUnmounted(() => {
  if (intervalId) clearInterval(intervalId)
})
</script>

<template>
  <Head><title>Discovering your business — Atlas</title></Head>
  <AuthLayout>
    <div class="text-center">
      <!-- Every attempted connector failed — an honest terminal state, not a dead end -->
      <div v-if="isCompletedWithErrors">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-red-50 flex items-center justify-center">
            <svg class="size-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
          </div>
        </div>
        <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-2">Atlas couldn't discover anything yet</h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-6">None of your declared assets could be reached automatically. Double-check the details below, or connect an account for real from Settings.</p>
        <div class="space-y-2 text-left max-w-sm mx-auto mb-6" aria-live="polite">
          <div v-for="connector in progress.connectors" :key="connector.type" class="flex items-center justify-between gap-3 px-3 py-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)]">
            <span class="text-sm text-[var(--color-text-primary)]">{{ connector.label }}</span>
            <span class="text-xs text-[var(--color-text-muted)]">{{ connectorIcon[connector.status] }} {{ connectorNote(connector) ?? connector.status }}</span>
          </div>
        </div>
        <div class="flex items-center justify-center gap-3">
          <a href="/onboarding" class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-raised)] transition-colors duration-[var(--duration-fast)]">Review asset details</a>
          <button
            v-if="progress.retry_available"
            type="button"
            :disabled="retrying"
            class="py-2.5 px-6 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
            @click="retryDiscovery"
          >
            {{ retrying ? 'Retrying…' : 'Try again' }}
          </button>
          <a href="/app" class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-raised)] transition-colors duration-[var(--duration-fast)]">Go to dashboard</a>
        </div>
      </div>

      <!-- Atlas learned the business, but the scan legitimately found nothing to act on -->
      <div v-else-if="isNoOpportunities">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-[var(--color-accent-50)] flex items-center justify-center">
            <svg class="size-6 text-[var(--color-accent-600)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
          </div>
        </div>
        <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-2">Atlas learned your business — no campaign opportunity yet</h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-3">Atlas gathered {{ progress.facts_created }} fact{{ progress.facts_created === 1 ? '' : 's' }} about your business, but didn't find a strong enough opportunity from this scan.</p>
        <p class="text-xs text-[var(--color-text-muted)] mb-6">Atlas keeps learning — a recommendation will appear on your dashboard as soon as it finds a strong opportunity.</p>
        <div class="flex items-center justify-center gap-3">
          <a href="/app" class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] transition-colors duration-[var(--duration-fast)]">Go to dashboard</a>
          <a href="/app/brain" class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-raised)] transition-colors duration-[var(--duration-fast)]">See what Atlas learned</a>
          <button
            v-if="progress.retry_available"
            type="button"
            :disabled="retrying"
            class="py-2.5 px-6 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-raised)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
            @click="retryDiscovery"
          >
            {{ retrying ? 'Retrying…' : 'Try again' }}
          </button>
        </div>
      </div>

      <!-- Discovery completed with a recommendation — fetchStatus() redirects
           there automatically; this only renders for the brief moment before
           that navigation completes. -->
      <div v-else-if="isCompleted">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-[var(--color-accent-50)] flex items-center justify-center">
            <svg class="size-6 text-[var(--color-accent-600)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
          </div>
        </div>
        <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-2">Atlas discovered your business</h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-4">Here's what Atlas found:</p>
        <ul class="text-left max-w-sm mx-auto space-y-1.5 text-sm text-[var(--color-text-secondary)] mb-6" aria-live="polite">
          <li v-for="connector in succeededConnectors" :key="connector.type">✓ {{ connector.label }}</li>
          <li>✓ Business Brain updated</li>
          <li>✓ Marketing Health recalculated</li>
          <li>✓ {{ progress.facts_created }} fact{{ progress.facts_created === 1 ? '' : 's' }} created</li>
          <li>✓ {{ progress.opportunities_found }} opportunit{{ progress.opportunities_found === 1 ? 'y' : 'ies' }} found</li>
          <li>✓ {{ progress.recommendations_generated }} recommendation{{ progress.recommendations_generated === 1 ? '' : 's' }} generated</li>
        </ul>
        <a :href="recommendationHref" class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] transition-colors duration-[var(--duration-fast)]">View my recommendation</a>
      </div>

      <!-- Timeout message (no error, but discovery is still running after 5 min) -->
      <div v-else-if="isTimedOut">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-[var(--color-accent-50)] flex items-center justify-center">
            <svg class="size-6 text-[var(--color-accent-600)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
          </div>
        </div>
        <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-2">This is taking a moment</h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-6">Atlas is still discovering your business. You can leave this page — your first recommendation will be waiting on the dashboard when it's ready.</p>
        <a href="/app" class="inline-block py-2.5 px-6 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] transition-colors duration-[var(--duration-fast)]">Go to dashboard</a>
      </div>

      <!-- Normal in-progress state -->
      <template v-else>
        <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1">
          {{ loading ? 'Checking in with Atlas…' : 'Atlas is discovering your business' }}
        </h1>
        <p class="text-sm text-[var(--color-text-muted)] mb-6">This usually takes a few minutes. You can leave and come back.</p>

        <!-- Four-stage progress -->
        <div class="flex items-center gap-1.5 mb-6" aria-live="polite">
          <template v-for="(stage, index) in STAGES" :key="stage">
            <div class="flex-1">
              <div
                :class="[
                  'h-1.5 rounded-full transition-colors duration-[var(--duration-smooth)]',
                  stageState(index) === 'pending' ? 'bg-[var(--color-border)]' : 'bg-[var(--color-accent-500)]',
                  stageState(index) === 'active' ? 'animate-pulse' : '',
                ]"
              />
              <p class="mt-1.5 text-xs" :class="stageState(index) === 'pending' ? 'text-[var(--color-text-muted)]' : 'text-[var(--color-text-primary)] font-medium'">
                {{ STAGE_LABELS[stage] }}
              </p>
            </div>
          </template>
        </div>

        <!-- Per-asset connector detail underneath Discover -->
        <div class="space-y-2 text-left max-w-sm mx-auto" aria-live="polite">
          <div
            v-for="connector in progress.connectors"
            :key="connector.type"
            class="flex items-center justify-between gap-3 px-3 py-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)]"
          >
            <span class="text-sm text-[var(--color-text-primary)]">{{ connectorIcon[connector.status] }} {{ connector.label }}</span>
            <span v-if="connectorNote(connector)" class="text-xs text-[var(--color-text-muted)]">{{ connectorNote(connector) }}</span>
          </div>
        </div>

        <div class="mt-6 flex items-center justify-center gap-4">
          <button
            v-if="progress.retry_available"
            type="button"
            :disabled="retrying"
            class="text-sm text-[var(--color-text-link)] hover:underline disabled:opacity-60"
            @click="retryDiscovery"
          >
            {{ retrying ? 'Retrying…' : 'Retry failed assets' }}
          </button>
          <a href="/app" class="text-sm text-[var(--color-text-link)] hover:underline">Skip to dashboard</a>
        </div>
      </template>
    </div>
  </AuthLayout>
</template>
