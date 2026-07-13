<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import {
  ONBOARDING_ASSET_TYPES,
  WEBSITE_PLATFORM_OPTIONS,
  BUSINESS_GOAL_OPTIONS,
  MARKETING_FREQUENCY_OPTIONS,
  MARKETING_OWNER_OPTIONS,
  PRIMARY_CTA_OPTIONS,
  MONTH_OPTIONS,
} from '@/lib/onboardingAssets'

interface EnabledAsset {
  type: string
  label: string
  importance: string
  handle_or_url: string | null
  metadata: Record<string, unknown>
}

const props = defineProps<{
  initial_step: 1 | 2 | 3 | 4 | 5 | 6 | 7
  enabled_assets: EnabledAsset[]
}>()

const TOTAL_STEPS = 7
const step = ref<1 | 2 | 3 | 4 | 5 | 6 | 7>(props.initial_step)

function back(): void {
  if (step.value > 1) {
    step.value = (step.value - 1) as typeof step.value
  }
}

// ── Step 1: Welcome — no data, no server round trip ─────────────────────────

function beginStep2(): void {
  step.value = 2
}

// ── Step 2: Company ──────────────────────────────────────────────────────────

const companyForm = useForm({
  name: '',
  industry: '',
  description: '',
})

function submitCompany(): void {
  companyForm.post('/onboarding/company', {
    onSuccess: () => { step.value = 3 },
  })
}

// ── Step 3: Business Goals ────────────────────────────────────────────────────

const goalsForm = useForm({
  goals: [] as string[],
})

function toggleGoal(value: string): void {
  const index = goalsForm.goals.indexOf(value)
  if (index === -1) {
    goalsForm.goals.push(value)
  } else {
    goalsForm.goals.splice(index, 1)
  }
}

function submitGoals(): void {
  goalsForm.post('/onboarding/goals', {
    onSuccess: () => { step.value = 4 },
  })
}

// ── Step 4: Marketing Assets ─────────────────────────────────────────────────

const enabledTypes = ref<string[]>(props.enabled_assets.map((a) => a.type))
const primaryTypes = ref<string[]>(props.enabled_assets.filter((a) => a.importance === 'primary').map((a) => a.type))
const primaryLimitError = ref(false)

function toggleEnabled(type: string): void {
  const index = enabledTypes.value.indexOf(type)
  if (index === -1) {
    enabledTypes.value.push(type)
  } else {
    enabledTypes.value.splice(index, 1)
    const primaryIndex = primaryTypes.value.indexOf(type)
    if (primaryIndex !== -1) primaryTypes.value.splice(primaryIndex, 1)
  }
}

function togglePrimary(type: string): void {
  const index = primaryTypes.value.indexOf(type)
  if (index !== -1) {
    primaryTypes.value.splice(index, 1)
    primaryLimitError.value = false
    return
  }

  if (primaryTypes.value.length >= 3) {
    primaryLimitError.value = true
    return
  }

  primaryLimitError.value = false
  primaryTypes.value.push(type)
}

const assetsForm = useForm({
  enabled: [] as string[],
  primary: [] as string[],
})

function seedAssetDetails(): void {
  for (const type of enabledTypes.value) {
    if (detailsForm.assets[type]) continue
    const existing = props.enabled_assets.find((a) => a.type === type)
    detailsForm.assets[type] = seedDetailsFor(type, existing)
  }
}

function submitAssets(): void {
  assetsForm.enabled = enabledTypes.value
  assetsForm.primary = primaryTypes.value
  assetsForm.post('/onboarding/assets', {
    onSuccess: () => {
      seedAssetDetails()
      step.value = 5
    },
  })
}

// ── Step 5: Asset Details ─────────────────────────────────────────────────────

function seedDetailsFor(type: string, existing: EnabledAsset | undefined): Record<string, string> {
  const metadata = existing?.metadata ?? {}
  const handleOrUrl = existing?.handle_or_url ?? ''

  switch (type) {
    case 'website':
      return { url: handleOrUrl, platform: (metadata.platform as string) ?? '' }
    case 'instagram':
    case 'facebook':
    case 'linkedin':
    case 'youtube':
    case 'x':
      return { url: handleOrUrl }
    case 'google_business_profile':
      return { business_name_or_url: handleOrUrl }
    case 'email':
      return { provider: (metadata.provider as string) ?? '', signup_url: (metadata.signup_url as string) ?? '' }
    case 'events':
    case 'print':
      return { description: (metadata.description as string) ?? '' }
    default:
      return {}
  }
}

const detailsForm = useForm({
  assets: Object.fromEntries(
    props.enabled_assets.map((a) => [a.type, seedDetailsFor(a.type, a)]),
  ) as Record<string, Record<string, string>>,
})

const enabledAssetTypes = computed(() =>
  ONBOARDING_ASSET_TYPES.filter((a) => enabledTypes.value.includes(a.type)),
)

function submitAssetDetails(): void {
  detailsForm.post('/onboarding/asset-details', {
    onSuccess: () => { step.value = 6 },
  })
}

// ── Step 6: Marketing Preferences ────────────────────────────────────────────

const preferencesForm = useForm({
  marketing_frequency: '',
  marketing_owner: '',
  is_seasonal: false,
  seasonal_months: [] as number[],
  primary_cta: '',
})

function toggleSeasonalMonth(month: number): void {
  const index = preferencesForm.seasonal_months.indexOf(month)
  if (index === -1) {
    preferencesForm.seasonal_months.push(month)
  } else {
    preferencesForm.seasonal_months.splice(index, 1)
  }
}

function submitPreferences(): void {
  preferencesForm.post('/onboarding/preferences', {
    onSuccess: () => { step.value = 7 },
  })
}

// ── Step 7: Discovery Placeholder ─────────────────────────────────────────────

const finishForm = useForm({})

function startDiscovery(): void {
  finishForm.post('/onboarding/finish')
}
</script>

<template>
  <Head><title>Set up your business — Atlas</title></Head>
  <AuthLayout>
    <!-- Step indicator -->
    <div v-if="step > 1" class="flex items-center gap-1.5 mb-6">
      <div
        v-for="n in TOTAL_STEPS"
        :key="n"
        :class="[
          'h-1.5 flex-1 rounded-full transition-colors duration-[var(--duration-smooth)]',
          n <= step ? 'bg-[var(--color-accent-500)]' : 'bg-[var(--color-border)]',
        ]"
      />
    </div>

    <!-- Step 1: Welcome -->
    <div v-if="step === 1" class="text-center">
      <h1 class="text-lg font-semibold text-[var(--color-text-primary)] mb-2">Welcome to Atlas</h1>
      <p class="text-sm text-[var(--color-text-muted)] mb-6 leading-relaxed">
        Let's teach Atlas about your business so it can build your Business Brain and generate personalized marketing recommendations.
      </p>
      <button
        type="button"
        class="w-full py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] transition-colors duration-[var(--duration-fast)]"
        @click="beginStep2"
      >
        Let's Begin
      </button>
    </div>

    <!-- Step 2: Company -->
    <div v-else-if="step === 2">
      <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1">Tell us about your business</h1>
      <p class="text-sm text-[var(--color-text-muted)] mb-5">A little context helps Atlas get this right from day one.</p>

      <form class="space-y-4" @submit.prevent="submitCompany">
        <div>
          <label for="company-name" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Company name</label>
          <input
            id="company-name"
            v-model="companyForm.name"
            type="text"
            required
            :class="[
              'w-full px-3 py-2 text-sm rounded-lg border bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] placeholder-[var(--color-text-placeholder)] transition-colors duration-[var(--duration-fast)]',
              companyForm.errors.name
                ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
                : 'border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]',
            ]"
            placeholder="Acme Comics"
          />
          <p v-if="companyForm.errors.name" class="mt-1 text-xs text-rose-600">{{ companyForm.errors.name }}</p>
        </div>

        <div>
          <label for="industry" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Industry</label>
          <input
            id="industry"
            v-model="companyForm.industry"
            type="text"
            required
            :class="[
              'w-full px-3 py-2 text-sm rounded-lg border bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] placeholder-[var(--color-text-placeholder)] transition-colors duration-[var(--duration-fast)]',
              companyForm.errors.industry
                ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
                : 'border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]',
            ]"
            placeholder="Collectibles, Automotive, etc."
          />
          <p v-if="companyForm.errors.industry" class="mt-1 text-xs text-rose-600">{{ companyForm.errors.industry }}</p>
        </div>

        <div>
          <label for="description" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">
            What does your business do? <span class="text-[var(--color-text-muted)] normal-case">(optional)</span>
          </label>
          <textarea
            id="description"
            v-model="companyForm.description"
            rows="3"
            class="w-full px-3 py-2 text-sm rounded-lg border bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] placeholder-[var(--color-text-placeholder)] border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)] transition-colors duration-[var(--duration-fast)]"
            placeholder="A few sentences about what you sell and who you sell it to."
          />
        </div>

        <button
          type="submit"
          :disabled="companyForm.processing"
          class="w-full py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        >
          {{ companyForm.processing ? 'Saving…' : 'Continue' }}
        </button>
      </form>
    </div>

    <!-- Step 3: Business Goals -->
    <div v-else-if="step === 3">
      <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1">What would you like Atlas to help you accomplish?</h1>
      <p class="text-sm text-[var(--color-text-muted)] mb-5">Pick as many as apply — this shapes the recommendations Atlas makes.</p>

      <form class="space-y-4" @submit.prevent="submitGoals">
        <div class="grid grid-cols-2 gap-2">
          <label
            v-for="goal in BUSINESS_GOAL_OPTIONS"
            :key="goal.value"
            :class="[
              'flex items-center gap-2 px-3 py-2 text-sm rounded-lg border cursor-pointer transition-colors duration-[var(--duration-fast)]',
              goalsForm.goals.includes(goal.value)
                ? 'border-[var(--color-accent-500)] bg-[var(--color-accent-50)] text-[var(--color-text-primary)]'
                : 'border-[var(--color-border)] bg-[var(--color-surface-elevated)] text-[var(--color-text-secondary)]',
            ]"
          >
            <input
              type="checkbox"
              class="size-4 rounded border-[var(--color-border-strong)] text-[var(--color-accent-600)] focus:ring-1 focus:ring-[var(--color-border-focus)]"
              :checked="goalsForm.goals.includes(goal.value)"
              @change="toggleGoal(goal.value)"
            />
            {{ goal.label }}
          </label>
        </div>
        <p v-if="goalsForm.errors.goals" class="text-xs text-rose-600">{{ goalsForm.errors.goals }}</p>

        <div class="flex gap-3">
          <button
            type="button"
            class="py-2.5 px-4 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
            @click="back"
          >
            Back
          </button>
          <button
            type="submit"
            :disabled="goalsForm.processing"
            class="flex-1 py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
          >
            {{ goalsForm.processing ? 'Saving…' : 'Continue' }}
          </button>
        </div>
      </form>
    </div>

    <!-- Step 4: Marketing Assets -->
    <div v-else-if="step === 4">
      <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1">Where can customers find your business?</h1>
      <p class="text-sm text-[var(--color-text-muted)] mb-1">Enable everything that applies, then mark up to three as primary.</p>
      <p v-if="primaryLimitError" class="text-xs text-rose-600 mb-3">You can mark at most three assets as primary.</p>

      <form class="space-y-4" :class="{ 'mt-4': !primaryLimitError }" @submit.prevent="submitAssets">
        <div class="space-y-2">
          <div
            v-for="asset in ONBOARDING_ASSET_TYPES"
            :key="asset.type"
            :class="[
              'flex items-center justify-between gap-3 px-3 py-2.5 rounded-lg border transition-colors duration-[var(--duration-fast)]',
              enabledTypes.includes(asset.type)
                ? 'border-[var(--color-accent-500)] bg-[var(--color-accent-50)]'
                : 'border-[var(--color-border)] bg-[var(--color-surface-elevated)]',
            ]"
          >
            <label class="flex items-center gap-2 text-sm text-[var(--color-text-primary)] cursor-pointer flex-1">
              <input
                type="checkbox"
                class="size-4 rounded border-[var(--color-border-strong)] text-[var(--color-accent-600)] focus:ring-1 focus:ring-[var(--color-border-focus)]"
                :checked="enabledTypes.includes(asset.type)"
                @change="toggleEnabled(asset.type)"
              />
              {{ asset.label }}
            </label>

            <label
              v-if="enabledTypes.includes(asset.type)"
              class="flex items-center gap-1.5 text-xs text-[var(--color-text-secondary)] cursor-pointer shrink-0"
            >
              <input
                type="checkbox"
                class="size-3.5 rounded border-[var(--color-border-strong)] text-[var(--color-accent-600)] focus:ring-1 focus:ring-[var(--color-border-focus)]"
                :checked="primaryTypes.includes(asset.type)"
                @change="togglePrimary(asset.type)"
              />
              Primary
            </label>
          </div>
        </div>
        <p v-if="assetsForm.errors.enabled" class="text-xs text-rose-600">{{ assetsForm.errors.enabled }}</p>
        <p v-if="assetsForm.errors.primary" class="text-xs text-rose-600">{{ assetsForm.errors.primary }}</p>

        <div class="flex gap-3">
          <button
            type="button"
            class="py-2.5 px-4 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
            @click="back"
          >
            Back
          </button>
          <button
            type="submit"
            :disabled="assetsForm.processing"
            class="flex-1 py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
          >
            {{ assetsForm.processing ? 'Saving…' : 'Continue' }}
          </button>
        </div>
      </form>
    </div>

    <!-- Step 5: Asset Details -->
    <div v-else-if="step === 5">
      <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1">Tell us a bit more about each one</h1>
      <p class="text-sm text-[var(--color-text-muted)] mb-5">Just enough for Atlas to find it — no passwords or logins.</p>

      <form class="space-y-4" @submit.prevent="submitAssetDetails">
        <div
          v-for="asset in enabledAssetTypes"
          :key="asset.type"
          class="p-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)]"
        >
          <p class="text-sm font-medium text-[var(--color-text-primary)] mb-2">{{ asset.label }}</p>

          <!-- Website -->
          <div v-if="asset.type === 'website'" class="space-y-2">
            <div>
              <label :for="`detail-${asset.type}-url`" class="block text-xs text-[var(--color-text-muted)] mb-1">URL</label>
              <input
                :id="`detail-${asset.type}-url`"
                v-model="detailsForm.assets.website.url"
                type="url"
                required
                placeholder="https://acmecomics.com"
                class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
              />
              <p v-if="detailsForm.errors[`assets.website.url`]" class="mt-1 text-xs text-rose-600">{{ detailsForm.errors[`assets.website.url`] }}</p>
            </div>
            <div>
              <label :for="`detail-${asset.type}-platform`" class="block text-xs text-[var(--color-text-muted)] mb-1">Platform</label>
              <select
                :id="`detail-${asset.type}-platform`"
                v-model="detailsForm.assets.website.platform"
                required
                class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
              >
                <option value="" disabled>Select a platform</option>
                <option v-for="p in WEBSITE_PLATFORM_OPTIONS" :key="p.value" :value="p.value">{{ p.label }}</option>
              </select>
              <p v-if="detailsForm.errors[`assets.website.platform`]" class="mt-1 text-xs text-rose-600">{{ detailsForm.errors[`assets.website.platform`] }}</p>
            </div>
          </div>

          <!-- Instagram / Facebook / LinkedIn / YouTube / X: single URL -->
          <div v-else-if="['instagram', 'facebook', 'linkedin', 'youtube', 'x'].includes(asset.type)">
            <label :for="`detail-${asset.type}-url`" class="block text-xs text-[var(--color-text-muted)] mb-1">
              {{ { instagram: 'Profile URL', facebook: 'Page URL', linkedin: 'Company URL', youtube: 'Channel URL', x: 'Profile URL' }[asset.type] }}
            </label>
            <input
              :id="`detail-${asset.type}-url`"
              v-model="detailsForm.assets[asset.type].url"
              type="url"
              required
              placeholder="https://..."
              class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
            />
            <p v-if="detailsForm.errors[`assets.${asset.type}.url`]" class="mt-1 text-xs text-rose-600">{{ detailsForm.errors[`assets.${asset.type}.url`] }}</p>
          </div>

          <!-- Google Business -->
          <div v-else-if="asset.type === 'google_business_profile'">
            <label :for="`detail-${asset.type}-value`" class="block text-xs text-[var(--color-text-muted)] mb-1">Business name or Google Maps URL</label>
            <input
              :id="`detail-${asset.type}-value`"
              v-model="detailsForm.assets.google_business_profile.business_name_or_url"
              type="text"
              required
              placeholder="Acme Comics, or a Google Maps link"
              class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
            />
            <p v-if="detailsForm.errors[`assets.google_business_profile.business_name_or_url`]" class="mt-1 text-xs text-rose-600">{{ detailsForm.errors[`assets.google_business_profile.business_name_or_url`] }}</p>
          </div>

          <!-- Email Newsletter -->
          <div v-else-if="asset.type === 'email'" class="space-y-2">
            <div>
              <label :for="`detail-${asset.type}-provider`" class="block text-xs text-[var(--color-text-muted)] mb-1">Provider (optional)</label>
              <input
                :id="`detail-${asset.type}-provider`"
                v-model="detailsForm.assets.email.provider"
                type="text"
                placeholder="Mailchimp, Klaviyo, etc."
                class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
              />
            </div>
            <div>
              <label :for="`detail-${asset.type}-signup`" class="block text-xs text-[var(--color-text-muted)] mb-1">Website signup URL (optional)</label>
              <input
                :id="`detail-${asset.type}-signup`"
                v-model="detailsForm.assets.email.signup_url"
                type="url"
                placeholder="https://..."
                class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
              />
            </div>
          </div>

          <!-- Events / Print -->
          <div v-else-if="['events', 'print'].includes(asset.type)">
            <label :for="`detail-${asset.type}-description`" class="block text-xs text-[var(--color-text-muted)] mb-1">Short description (optional)</label>
            <input
              :id="`detail-${asset.type}-description`"
              v-model="detailsForm.assets[asset.type].description"
              type="text"
              :placeholder="asset.type === 'events' ? 'Monthly in-store auction night' : 'Quarterly postcard mailer'"
              class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
            />
          </div>
        </div>

        <div class="flex gap-3">
          <button
            type="button"
            class="py-2.5 px-4 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
            @click="back"
          >
            Back
          </button>
          <button
            type="submit"
            :disabled="detailsForm.processing"
            class="flex-1 py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
          >
            {{ detailsForm.processing ? 'Saving…' : 'Continue' }}
          </button>
        </div>
      </form>
    </div>

    <!-- Step 6: Marketing Preferences -->
    <div v-else-if="step === 6">
      <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1">A few quick preferences</h1>
      <p class="text-sm text-[var(--color-text-muted)] mb-5">This helps Atlas pace and shape its recommendations.</p>

      <form class="space-y-4" @submit.prevent="submitPreferences">
        <div>
          <label for="marketing-frequency" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">How often do you currently market?</label>
          <select
            id="marketing-frequency"
            v-model="preferencesForm.marketing_frequency"
            required
            class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
          >
            <option value="" disabled>Select one</option>
            <option v-for="f in MARKETING_FREQUENCY_OPTIONS" :key="f.value" :value="f.value">{{ f.label }}</option>
          </select>
          <p v-if="preferencesForm.errors.marketing_frequency" class="mt-1 text-xs text-rose-600">{{ preferencesForm.errors.marketing_frequency }}</p>
        </div>

        <div>
          <label for="marketing-owner" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Who handles marketing?</label>
          <select
            id="marketing-owner"
            v-model="preferencesForm.marketing_owner"
            required
            class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
          >
            <option value="" disabled>Select one</option>
            <option v-for="o in MARKETING_OWNER_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
          <p v-if="preferencesForm.errors.marketing_owner" class="mt-1 text-xs text-rose-600">{{ preferencesForm.errors.marketing_owner }}</p>
        </div>

        <div>
          <span class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Is your business seasonal?</span>
          <div class="flex gap-2">
            <button
              type="button"
              :class="[
                'flex-1 py-2 px-3 text-sm rounded-lg border transition-colors duration-[var(--duration-fast)]',
                preferencesForm.is_seasonal
                  ? 'border-[var(--color-accent-500)] bg-[var(--color-accent-50)] text-[var(--color-text-primary)]'
                  : 'border-[var(--color-border)] bg-white text-[var(--color-text-secondary)]',
              ]"
              @click="preferencesForm.is_seasonal = true"
            >
              Yes
            </button>
            <button
              type="button"
              :class="[
                'flex-1 py-2 px-3 text-sm rounded-lg border transition-colors duration-[var(--duration-fast)]',
                !preferencesForm.is_seasonal
                  ? 'border-[var(--color-accent-500)] bg-[var(--color-accent-50)] text-[var(--color-text-primary)]'
                  : 'border-[var(--color-border)] bg-white text-[var(--color-text-secondary)]',
              ]"
              @click="preferencesForm.is_seasonal = false; preferencesForm.seasonal_months = []"
            >
              No
            </button>
          </div>
        </div>

        <div v-if="preferencesForm.is_seasonal">
          <label class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Which months?</label>
          <div class="grid grid-cols-3 gap-2">
            <label
              v-for="month in MONTH_OPTIONS"
              :key="month.value"
              :class="[
                'flex items-center gap-1.5 px-2 py-1.5 text-xs rounded-lg border cursor-pointer transition-colors duration-[var(--duration-fast)]',
                preferencesForm.seasonal_months.includes(month.value)
                  ? 'border-[var(--color-accent-500)] bg-[var(--color-accent-50)] text-[var(--color-text-primary)]'
                  : 'border-[var(--color-border)] bg-white text-[var(--color-text-secondary)]',
              ]"
            >
              <input
                type="checkbox"
                class="size-3.5 rounded border-[var(--color-border-strong)] text-[var(--color-accent-600)]"
                :checked="preferencesForm.seasonal_months.includes(month.value)"
                @change="toggleSeasonalMonth(month.value)"
              />
              {{ month.label }}
            </label>
          </div>
          <p v-if="preferencesForm.errors.seasonal_months" class="mt-1 text-xs text-rose-600">{{ preferencesForm.errors.seasonal_months }}</p>
        </div>

        <div>
          <label for="primary-cta" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">What's the main thing you want customers to do?</label>
          <select
            id="primary-cta"
            v-model="preferencesForm.primary_cta"
            required
            class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
          >
            <option value="" disabled>Select one</option>
            <option v-for="c in PRIMARY_CTA_OPTIONS" :key="c.value" :value="c.value">{{ c.label }}</option>
          </select>
          <p v-if="preferencesForm.errors.primary_cta" class="mt-1 text-xs text-rose-600">{{ preferencesForm.errors.primary_cta }}</p>
        </div>

        <div class="flex gap-3">
          <button
            type="button"
            class="py-2.5 px-4 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
            @click="back"
          >
            Back
          </button>
          <button
            type="submit"
            :disabled="preferencesForm.processing"
            class="flex-1 py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
          >
            {{ preferencesForm.processing ? 'Saving…' : 'Continue' }}
          </button>
        </div>
      </form>
    </div>

    <!-- Step 7: Discovery Placeholder -->
    <div v-else class="text-center">
      <div class="mb-5 flex items-center justify-center">
        <div class="size-12 rounded-full bg-[var(--color-accent-50)] flex items-center justify-center">
          <svg class="size-6 text-[var(--color-accent-600)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
        </div>
      </div>
      <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-2">Atlas is ready to learn about your business.</h1>
      <p class="text-sm text-[var(--color-text-muted)] mb-6">Everything is ready. The next phase will begin discovering your marketing assets and building your Business Brain.</p>
      <button
        type="button"
        :disabled="finishForm.processing"
        class="w-full py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        @click="startDiscovery"
      >
        {{ finishForm.processing ? 'Starting…' : 'Start Discovery' }}
      </button>
    </div>
  </AuthLayout>
</template>
