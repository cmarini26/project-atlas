<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { router, Head } from '@inertiajs/vue3'
import {
  ExclamationTriangleIcon,
  LightBulbIcon,
  CheckCircleIcon,
  ClockIcon,
  ChevronDownIcon,
  ChevronUpIcon,
} from '@heroicons/vue/24/outline'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import Button from '@/Components/UI/Button.vue'

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
// Per-asset connector detail is implementation detail most people don't
// need to read while waiting — collapsed by default during the normal
// in-progress state. Not used in the completed_with_errors state, where
// that same detail is always visible because it's actually actionable there.
const detailsExpanded = ref(false)
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

const checkingSummary = computed((): string => {
  const count = progress.value.connectors.length
  if (count === 0) return 'Atlas is checking your declared marketing assets.'
  return `Atlas is checking ${count} declared asset${count === 1 ? '' : 's'}.`
})

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
          <div class="size-12 rounded-full bg-rose-50 flex items-center justify-center">
            <ExclamationTriangleIcon class="size-6 text-rose-500" aria-hidden="true" />
          </div>
        </div>
        <h1 class="text-[length:var(--text-subheading)] font-semibold text-[var(--color-text-primary)] mb-2">Atlas couldn't discover anything yet</h1>
        <p class="text-[length:var(--text-body)] text-[var(--color-text-muted)] mb-6">None of your declared assets could be reached automatically. Double-check the details below, or connect an account for real from Settings.</p>
        <div class="space-y-2 text-left max-w-sm mx-auto mb-6" aria-live="polite">
          <div v-for="connector in progress.connectors" :key="connector.type" class="flex items-center justify-between gap-3 px-3 py-2 rounded-[var(--radius-sm)] border border-[var(--color-border)] bg-[var(--color-surface-subtle)]">
            <span class="text-sm text-[var(--color-text-primary)]">{{ connector.label }}</span>
            <span class="text-xs text-[var(--color-text-muted)]">{{ connectorIcon[connector.status] }} {{ connectorNote(connector) ?? connector.status }}</span>
          </div>
        </div>
        <div class="flex items-center justify-center gap-3">
          <Button as="a" href="/onboarding" variant="secondary">Review asset details</Button>
          <Button v-if="progress.retry_available" :loading="retrying" @click="retryDiscovery">
            {{ retrying ? 'Retrying…' : 'Try again' }}
          </Button>
          <Button as="a" href="/app" variant="secondary">Go to dashboard</Button>
        </div>
      </div>

      <!-- Atlas learned the business, but the scan legitimately found nothing to act on -->
      <div v-else-if="isNoOpportunities">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-[var(--color-accent-50)] flex items-center justify-center">
            <LightBulbIcon class="size-6 text-[var(--color-accent-600)]" aria-hidden="true" />
          </div>
        </div>
        <h1 class="text-[length:var(--text-subheading)] font-semibold text-[var(--color-text-primary)] mb-2">Atlas learned your business — no campaign opportunity yet</h1>
        <p class="text-[length:var(--text-body)] text-[var(--color-text-muted)] mb-3">Atlas gathered {{ progress.facts_created }} fact{{ progress.facts_created === 1 ? '' : 's' }} about your business, but didn't find a strong enough opportunity from this scan.</p>
        <p class="text-xs text-[var(--color-text-muted)] mb-6">Atlas keeps learning — a recommendation will appear on your dashboard as soon as it finds a strong opportunity.</p>
        <div class="flex items-center justify-center gap-3">
          <Button as="a" href="/app">Go to dashboard</Button>
          <Button as="a" href="/app/brain" variant="secondary">See what Atlas learned</Button>
          <Button v-if="progress.retry_available" variant="secondary" :loading="retrying" @click="retryDiscovery">
            {{ retrying ? 'Retrying…' : 'Try again' }}
          </Button>
        </div>
      </div>

      <!-- Discovery completed with a recommendation — fetchStatus() redirects
           there automatically; this only renders for the brief moment before
           that navigation completes. -->
      <div v-else-if="isCompleted">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-[var(--color-accent-50)] flex items-center justify-center">
            <CheckCircleIcon class="size-6 text-[var(--color-accent-600)]" aria-hidden="true" />
          </div>
        </div>
        <h1 class="text-[length:var(--text-subheading)] font-semibold text-[var(--color-text-primary)] mb-2">Atlas discovered your business</h1>
        <p class="text-[length:var(--text-body)] text-[var(--color-text-muted)] mb-4">Here's what Atlas found:</p>
        <ul class="text-left max-w-sm mx-auto space-y-1.5 text-sm text-[var(--color-text-secondary)] mb-6" aria-live="polite">
          <li v-for="connector in succeededConnectors" :key="connector.type">✓ {{ connector.label }}</li>
          <li>✓ Business Brain updated</li>
          <li>✓ Marketing Health recalculated</li>
          <li>✓ {{ progress.facts_created }} fact{{ progress.facts_created === 1 ? '' : 's' }} created</li>
          <li>✓ {{ progress.opportunities_found }} opportunit{{ progress.opportunities_found === 1 ? 'y' : 'ies' }} found</li>
          <li>✓ {{ progress.recommendations_generated }} recommendation{{ progress.recommendations_generated === 1 ? '' : 's' }} generated</li>
        </ul>
        <Button as="a" :href="recommendationHref">View my recommendation</Button>
      </div>

      <!-- Timeout message (no error, but discovery is still running after 5 min) -->
      <div v-else-if="isTimedOut">
        <div class="mb-5 flex items-center justify-center">
          <div class="size-12 rounded-full bg-[var(--color-accent-50)] flex items-center justify-center">
            <ClockIcon class="size-6 text-[var(--color-accent-600)]" aria-hidden="true" />
          </div>
        </div>
        <h1 class="text-[length:var(--text-subheading)] font-semibold text-[var(--color-text-primary)] mb-2">This is taking a moment</h1>
        <p class="text-[length:var(--text-body)] text-[var(--color-text-muted)] mb-6">Atlas is still discovering your business. You can leave this page — your first recommendation will be waiting on the dashboard when it's ready.</p>
        <Button as="a" href="/app">Go to dashboard</Button>
      </div>

      <!-- Normal in-progress state -->
      <template v-else>
        <h1 class="text-[length:var(--text-subheading)] font-semibold text-[var(--color-text-primary)] mb-1">
          {{ loading ? 'Checking in with Atlas…' : 'Atlas is discovering your business' }}
        </h1>
        <p class="text-[length:var(--text-body)] text-[var(--color-text-muted)] mb-6">{{ checkingSummary }} This usually takes a few minutes — you can leave and come back.</p>

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

        <!-- Per-asset connector detail — collapsed by default; this is
             implementation detail, not something most people need to read
             while waiting. -->
        <button
          type="button"
          class="inline-flex items-center gap-1 text-xs text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)] mb-3"
          @click="detailsExpanded = !detailsExpanded"
        >
          {{ detailsExpanded ? 'Hide details' : 'Show details' }}
          <ChevronUpIcon v-if="detailsExpanded" class="size-3.5" aria-hidden="true" />
          <ChevronDownIcon v-else class="size-3.5" aria-hidden="true" />
        </button>

        <div v-if="detailsExpanded" class="space-y-2 text-left max-w-sm mx-auto" aria-live="polite">
          <div
            v-for="connector in progress.connectors"
            :key="connector.type"
            class="flex items-center justify-between gap-3 px-3 py-2 rounded-[var(--radius-sm)] border border-[var(--color-border)] bg-[var(--color-surface-subtle)]"
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
