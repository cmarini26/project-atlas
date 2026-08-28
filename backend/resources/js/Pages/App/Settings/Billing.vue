<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import Card from '@/Components/UI/Card.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import { ArrowLeftIcon, CreditCardIcon } from '@heroicons/vue/24/outline'

defineOptions({ layout: AppLayout })

interface Billing {
  has_customer: boolean
  has_subscription: boolean
  status: string | null
  price_id: string | null
  current_period_ends_at: string | null
  cancel_at_period_end: boolean
  beta_access_override: boolean
  grants_access: boolean
}

const props = defineProps<{
  billing: Billing
  checkout_available: boolean
  can_manage: boolean
  checkout_result: 'success' | 'cancelled' | null
}>()

const statusLabel = computed(() => {
  const map: Record<string, string> = {
    trialing: 'Trialing',
    active: 'Active',
    past_due: 'Past due',
    canceled: 'Canceled',
    unpaid: 'Unpaid',
    incomplete: 'Incomplete',
    incomplete_expired: 'Incomplete',
    paused: 'Paused',
  }
  return props.billing.status ? (map[props.billing.status] ?? props.billing.status) : 'No subscription'
})

const statusVariant = computed<'success' | 'default' | 'muted'>(() => {
  if (props.billing.beta_access_override) return 'success'
  if (['trialing', 'active'].includes(props.billing.status ?? '')) return 'success'
  if (props.billing.status === 'past_due') return 'default'
  return 'muted'
})

function periodDate(): string {
  if (!props.billing.current_period_ends_at) return ''
  return new Date(props.billing.current_period_ends_at).toLocaleDateString('en-US', {
    month: 'long',
    day: 'numeric',
    year: 'numeric',
  })
}

function startCheckout(): void {
  router.post('/app/settings/billing/checkout', {}, { preserveScroll: true })
}

function openPortal(): void {
  router.post('/app/settings/billing/portal', {}, { preserveScroll: true })
}
</script>

<template>
  <Head><title>Billing — Atlas</title></Head>

  <div class="max-w-3xl space-y-6">
    <Link href="/app/settings" class="inline-flex items-center gap-2 text-sm font-semibold text-[var(--color-text-link)] hover:underline">
      <ArrowLeftIcon class="size-4" />
      Settings
    </Link>

    <PageHeader
      title="Billing"
      description="Your Atlas subscription. Billing is handled by Stripe — Atlas never sees your card details."
      :icon="CreditCardIcon"
    />

    <div
      v-if="checkout_result === 'success'"
      class="rounded-[var(--radius-md)] border border-[var(--color-accent-200)] bg-[var(--color-accent-50)] px-4 py-3 text-sm text-[var(--color-text-primary)]"
    >
      Thanks — your subscription is being set up. This page updates automatically once Stripe confirms it (usually within a minute).
    </div>
    <div
      v-else-if="checkout_result === 'cancelled'"
      class="rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface-panel)] px-4 py-3 text-sm text-[var(--color-text-secondary)]"
    >
      Checkout was cancelled. Nothing has changed — you can start again whenever you're ready.
    </div>

    <Card>
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.12em] text-[var(--color-text-muted)]">Subscription status</p>
          <div class="mt-1 flex items-center gap-2">
            <Badge :variant="statusVariant">{{ statusLabel }}</Badge>
            <span v-if="billing.beta_access_override" class="text-xs text-[var(--color-text-muted)]">beta access granted by Atlas</span>
          </div>
        </div>
      </div>

      <dl class="mt-4 grid gap-3 sm:grid-cols-2">
        <div v-if="billing.has_subscription && billing.current_period_ends_at">
          <dt class="text-xs font-semibold uppercase tracking-[0.1em] text-[var(--color-text-muted)]">
            {{ billing.cancel_at_period_end ? 'Access ends' : 'Renews' }}
          </dt>
          <dd class="mt-1 text-sm text-[var(--color-text-secondary)]">{{ periodDate() }}</dd>
        </div>
      </dl>

      <p
        v-if="billing.status === 'past_due'"
        class="mt-4 rounded-[var(--radius-sm)] bg-amber-50 px-3 py-2 text-sm text-amber-900"
      >
        Your last payment didn't go through. Update your payment method to keep Atlas running.
      </p>
      <p
        v-else-if="billing.cancel_at_period_end"
        class="mt-4 text-sm text-[var(--color-text-muted)]"
      >
        Your subscription is set to cancel at the end of the current period. You can resume it from the billing portal.
      </p>

      <div v-if="can_manage" class="mt-5 flex flex-wrap gap-3">
        <button
          v-if="!billing.has_subscription && checkout_available"
          type="button"
          class="rounded-[var(--radius-sm)] bg-[var(--color-accent-600)] px-5 py-2.5 text-sm font-semibold text-white"
          @click="startCheckout"
        >
          Subscribe
        </button>
        <button
          v-if="billing.has_customer"
          type="button"
          class="rounded-[var(--radius-sm)] border border-[var(--color-border)] px-5 py-2.5 text-sm font-semibold text-[var(--color-text-primary)] hover:bg-[var(--color-surface-panel)]"
          @click="openPortal"
        >
          Manage billing
        </button>
        <p v-if="!checkout_available && !billing.has_subscription" class="text-sm text-[var(--color-text-muted)]">
          Subscriptions aren't available yet — check back soon.
        </p>
      </div>
      <p v-else class="mt-5 text-sm text-[var(--color-text-muted)]">
        Only company owners and admins can change billing.
      </p>
    </Card>
  </div>
</template>
