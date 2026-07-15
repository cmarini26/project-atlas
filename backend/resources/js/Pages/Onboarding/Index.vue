<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import { CheckCircleIcon } from '@heroicons/vue/24/outline'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import Button from '@/Components/UI/Button.vue'
import FormField from '@/Components/UI/FormField.vue'
import Input from '@/Components/UI/Input.vue'
import Select from '@/Components/UI/Select.vue'
import Textarea from '@/Components/UI/Textarea.vue'
import ChoiceChip from '@/Components/UI/ChoiceChip.vue'
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

function toggleEnabled(type: string): void {
  const index = enabledTypes.value.indexOf(type)
  if (index === -1) {
    enabledTypes.value.push(type)
  } else {
    enabledTypes.value.splice(index, 1)
  }
}

// Which enabled assets Atlas treats as "primary" is no longer a decision a
// first-time user has to make here — a brand-new user has no basis yet for
// judging which asset matters most. Auto-default from a fixed priority
// order (Website and Google Business first, since those are what Discovery
// can act on), capped at 3, same as before. Still editable later from
// /app/settings/marketing-presence.
const PRIMARY_PRIORITY = [
  'website', 'google_business_profile', 'instagram', 'facebook',
  'linkedin', 'x', 'youtube', 'email', 'events', 'print',
]

function autoPrimaryTypes(enabled: string[]): string[] {
  return PRIMARY_PRIORITY.filter((type) => enabled.includes(type)).slice(0, 3)
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
  assetsForm.primary = autoPrimaryTypes(enabledTypes.value)
  assetsForm.post('/onboarding/assets', {
    onSuccess: () => {
      seedAssetDetails()
      // Only Website currently needs details collected up front (Discovery
      // can't act on anything else yet) — skip straight past step 5 when
      // nothing enabled needs it, instead of showing an empty step.
      if (enabledAssetTypes.value.length === 0) {
        submitAssetDetails()
      } else {
        step.value = 5
      }
    },
  })
}

// ── Step 5: Asset Details ─────────────────────────────────────────────────────
// Deliberately Website-only — every other asset type is declared now and
// detailed later from Settings, since Discovery can't act on those details
// yet anyway.

function seedDetailsFor(type: string, existing: EnabledAsset | undefined): Record<string, string> {
  if (type !== 'website') return {}

  const metadata = existing?.metadata ?? {}
  const handleOrUrl = existing?.handle_or_url ?? ''

  return { url: handleOrUrl, platform: (metadata.platform as string) ?? '' }
}

const detailsForm = useForm({
  assets: Object.fromEntries(
    props.enabled_assets.map((a) => [a.type, seedDetailsFor(a.type, a)]),
  ) as Record<string, Record<string, string>>,
})

const enabledAssetTypes = computed(() =>
  ONBOARDING_ASSET_TYPES.filter((a) => a.requiresDetails && enabledTypes.value.includes(a.type)),
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
      <h1 class="text-[length:var(--text-heading)] font-semibold text-[var(--color-text-primary)] mb-2">Welcome to Atlas</h1>
      <p class="text-[length:var(--text-body)] text-[var(--color-text-muted)] mb-6 leading-relaxed">
        Let's teach Atlas about your business so it can build your Business Brain and generate personalized marketing recommendations.
      </p>
      <Button full-width @click="beginStep2">Let's Begin</Button>
    </div>

    <!-- Step 2: Company -->
    <div v-else-if="step === 2">
      <h1 class="text-[length:var(--text-subheading)] font-semibold text-[var(--color-text-primary)] mb-1">Tell us about your business</h1>
      <p class="text-[length:var(--text-body)] text-[var(--color-text-muted)] mb-5">A little context helps Atlas get this right from day one.</p>

      <form class="space-y-4" @submit.prevent="submitCompany">
        <FormField label="Company name" for="company-name" :error="companyForm.errors.name">
          <Input id="company-name" v-model="companyForm.name" required :invalid="!!companyForm.errors.name" placeholder="Acme Comics" />
        </FormField>

        <FormField label="Industry" for="industry" :error="companyForm.errors.industry">
          <Input id="industry" v-model="companyForm.industry" required :invalid="!!companyForm.errors.industry" placeholder="Collectibles, Automotive, etc." />
        </FormField>

        <FormField label="What does your business do?" for="description" optional>
          <Textarea id="description" v-model="companyForm.description" placeholder="A few sentences about what you sell and who you sell it to." />
        </FormField>

        <Button type="submit" full-width :loading="companyForm.processing">
          {{ companyForm.processing ? 'Saving…' : 'Continue' }}
        </Button>
      </form>
    </div>

    <!-- Step 3: Business Goals -->
    <div v-else-if="step === 3">
      <h1 class="text-[length:var(--text-subheading)] font-semibold text-[var(--color-text-primary)] mb-1">What would you like Atlas to help you accomplish?</h1>
      <p class="text-[length:var(--text-body)] text-[var(--color-text-muted)] mb-5">Pick as many as apply — this shapes the recommendations Atlas makes.</p>

      <form class="space-y-4" @submit.prevent="submitGoals">
        <div class="grid grid-cols-2 gap-2">
          <ChoiceChip
            v-for="goal in BUSINESS_GOAL_OPTIONS"
            :key="goal.value"
            :model-value="goalsForm.goals.includes(goal.value)"
            @update:model-value="toggleGoal(goal.value)"
          >
            {{ goal.label }}
          </ChoiceChip>
        </div>
        <p v-if="goalsForm.errors.goals" class="text-xs text-rose-600">{{ goalsForm.errors.goals }}</p>

        <div class="flex gap-3">
          <Button variant="secondary" @click="back">Back</Button>
          <Button type="submit" full-width :loading="goalsForm.processing">
            {{ goalsForm.processing ? 'Saving…' : 'Continue' }}
          </Button>
        </div>
      </form>
    </div>

    <!-- Step 4: Marketing Assets -->
    <div v-else-if="step === 4">
      <h1 class="text-[length:var(--text-subheading)] font-semibold text-[var(--color-text-primary)] mb-1">Where can customers find your business?</h1>
      <p class="text-[length:var(--text-body)] text-[var(--color-text-muted)] mb-5">Enable everything that applies — you can add the details later.</p>

      <form class="space-y-4" @submit.prevent="submitAssets">
        <div class="space-y-2">
          <ChoiceChip
            v-for="asset in ONBOARDING_ASSET_TYPES"
            :key="asset.type"
            :model-value="enabledTypes.includes(asset.type)"
            class="w-full"
            @update:model-value="toggleEnabled(asset.type)"
          >
            {{ asset.label }}
          </ChoiceChip>
        </div>
        <p v-if="assetsForm.errors.enabled" class="text-xs text-rose-600">{{ assetsForm.errors.enabled }}</p>

        <div class="flex gap-3">
          <Button variant="secondary" @click="back">Back</Button>
          <Button type="submit" full-width :loading="assetsForm.processing">
            {{ assetsForm.processing ? 'Saving…' : 'Continue' }}
          </Button>
        </div>
      </form>
    </div>

    <!-- Step 5: Asset Details (Website only — see script comment) -->
    <div v-else-if="step === 5">
      <h1 class="text-[length:var(--text-subheading)] font-semibold text-[var(--color-text-primary)] mb-1">Tell us a bit more about your website</h1>
      <p class="text-[length:var(--text-body)] text-[var(--color-text-muted)] mb-5">Just enough for Atlas to find it — no passwords or logins.</p>

      <form class="space-y-4" @submit.prevent="submitAssetDetails">
        <template v-for="asset in enabledAssetTypes" :key="asset.type">
          <template v-if="asset.type === 'website'">
            <FormField label="URL" for="detail-website-url" :error="detailsForm.errors['assets.website.url']">
              <Input
                id="detail-website-url"
                v-model="detailsForm.assets.website.url"
                type="url"
                required
                :invalid="!!detailsForm.errors['assets.website.url']"
                placeholder="https://acmecomics.com"
              />
            </FormField>
            <FormField label="Platform" for="detail-website-platform" :error="detailsForm.errors['assets.website.platform']">
              <Select
                id="detail-website-platform"
                v-model="detailsForm.assets.website.platform"
                required
                placeholder="Select a platform"
                :invalid="!!detailsForm.errors['assets.website.platform']"
              >
                <option v-for="p in WEBSITE_PLATFORM_OPTIONS" :key="p.value" :value="p.value">{{ p.label }}</option>
              </Select>
            </FormField>
          </template>
        </template>

        <div class="flex gap-3">
          <Button variant="secondary" @click="back">Back</Button>
          <Button type="submit" full-width :loading="detailsForm.processing">
            {{ detailsForm.processing ? 'Saving…' : 'Continue' }}
          </Button>
        </div>
      </form>
    </div>

    <!-- Step 6: Marketing Preferences -->
    <div v-else-if="step === 6">
      <h1 class="text-[length:var(--text-subheading)] font-semibold text-[var(--color-text-primary)] mb-1">A few quick preferences</h1>
      <p class="text-[length:var(--text-body)] text-[var(--color-text-muted)] mb-5">This helps Atlas pace and shape its recommendations.</p>

      <form class="space-y-4" @submit.prevent="submitPreferences">
        <FormField label="How often do you currently market?" for="marketing-frequency" :error="preferencesForm.errors.marketing_frequency">
          <Select id="marketing-frequency" v-model="preferencesForm.marketing_frequency" required placeholder="Select one" :invalid="!!preferencesForm.errors.marketing_frequency">
            <option v-for="f in MARKETING_FREQUENCY_OPTIONS" :key="f.value" :value="f.value">{{ f.label }}</option>
          </Select>
        </FormField>

        <FormField label="Who handles marketing?" for="marketing-owner" :error="preferencesForm.errors.marketing_owner">
          <Select id="marketing-owner" v-model="preferencesForm.marketing_owner" required placeholder="Select one" :invalid="!!preferencesForm.errors.marketing_owner">
            <option v-for="o in MARKETING_OWNER_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
          </Select>
        </FormField>

        <div>
          <span class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Is your business seasonal?</span>
          <div class="flex gap-2">
            <Button
              type="button"
              :variant="preferencesForm.is_seasonal ? 'primary' : 'secondary'"
              full-width
              @click="preferencesForm.is_seasonal = true"
            >
              Yes
            </Button>
            <Button
              type="button"
              :variant="!preferencesForm.is_seasonal ? 'primary' : 'secondary'"
              full-width
              @click="preferencesForm.is_seasonal = false; preferencesForm.seasonal_months = []"
            >
              No
            </Button>
          </div>
        </div>

        <div v-if="preferencesForm.is_seasonal">
          <label class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Which months?</label>
          <div class="grid grid-cols-3 gap-2">
            <ChoiceChip
              v-for="month in MONTH_OPTIONS"
              :key="month.value"
              size="sm"
              :model-value="preferencesForm.seasonal_months.includes(month.value)"
              @update:model-value="toggleSeasonalMonth(month.value)"
            >
              {{ month.label }}
            </ChoiceChip>
          </div>
          <p v-if="preferencesForm.errors.seasonal_months" class="mt-1 text-xs text-rose-600">{{ preferencesForm.errors.seasonal_months }}</p>
        </div>

        <FormField label="What's the main thing you want customers to do?" for="primary-cta" :error="preferencesForm.errors.primary_cta">
          <Select id="primary-cta" v-model="preferencesForm.primary_cta" required placeholder="Select one" :invalid="!!preferencesForm.errors.primary_cta">
            <option v-for="c in PRIMARY_CTA_OPTIONS" :key="c.value" :value="c.value">{{ c.label }}</option>
          </Select>
        </FormField>

        <div class="flex gap-3">
          <Button variant="secondary" @click="back">Back</Button>
          <Button type="submit" full-width :loading="preferencesForm.processing">
            {{ preferencesForm.processing ? 'Saving…' : 'Continue' }}
          </Button>
        </div>
      </form>
    </div>

    <!-- Step 7: Discovery Placeholder -->
    <div v-else class="text-center">
      <div class="mb-5 flex items-center justify-center">
        <div class="size-12 rounded-full bg-[var(--color-accent-50)] flex items-center justify-center">
          <CheckCircleIcon class="size-6 text-[var(--color-accent-600)]" aria-hidden="true" />
        </div>
      </div>
      <h1 class="text-[length:var(--text-subheading)] font-semibold text-[var(--color-text-primary)] mb-2">Atlas is ready to learn about your business.</h1>
      <p class="text-[length:var(--text-body)] text-[var(--color-text-muted)] mb-6">Everything is ready. The next phase will begin discovering your marketing assets and building your Business Brain.</p>
      <Button full-width :loading="finishForm.processing" @click="startDiscovery">
        {{ finishForm.processing ? 'Starting…' : 'Start Discovery' }}
      </Button>
    </div>
  </AuthLayout>
</template>
