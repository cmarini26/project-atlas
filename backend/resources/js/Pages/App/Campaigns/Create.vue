<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AssetMediaPreview from '@/Components/Assets/AssetMediaPreview.vue'
import Card from '@/Components/UI/Card.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import { ArrowLeftIcon, MegaphoneIcon } from '@heroicons/vue/24/outline'

defineOptions({ layout: AppLayout })

type SourceAsset = {
  id: string
  type: string
  title: string
  description: string | null
  media_url: string | null
  media_mime_type: string | null
}

type Channel = {
  id: string
  type: string
  name: string
}

const props = defineProps<{
  assets: SourceAsset[]
  channels: Channel[]
  initial_asset_ids: string[]
}>()

const form = useForm({
  title: '',
  goal: 'conversion',
  objective: '',
  audience: '',
  guidance: '',
  source_asset_ids: [...props.initial_asset_ids],
  generate_imagery: false,
  channel_ids: [] as string[],
  starts_at: '',
  ends_at: '',
})

// Expand the "Use my own assets" section up front only when an asset was
// pre-selected (e.g. arriving from the Asset Library with ?asset_id=).
const assetsOpen = ref(props.initial_asset_ids.length > 0)

const assetLabels: Record<string, string> = {
  product_service: 'Product or service',
  promotion_event: 'Promotion or event',
  photo_video: 'Photo or video',
  document_case_study: 'Document or case study',
  webpage_blog_post: 'Webpage or blog post',
  brand_material: 'Brand material',
}

const channelLabels: Record<string, string> = {
  facebook: 'Facebook',
  instagram: 'Instagram',
  linkedin: 'LinkedIn',
  x: 'X',
  email: 'Email',
  sms: 'SMS',
  blog: 'Blog',
  landing_page: 'Landing page',
}

const objectiveReady = computed(() => form.objective.trim().length >= 20)
const canSubmit = computed(() => objectiveReady.value && props.channels.length > 0 && !form.processing)
const selectedAssetCount = computed(() => form.source_asset_ids.length)

function submit(): void {
  if (!canSubmit.value) return
  form.post('/app/campaigns', { preserveScroll: true })
}
</script>

<template>
  <Head><title>Create campaign — Atlas</title></Head>

  <div class="max-w-5xl space-y-6">
    <Link href="/app/campaigns" class="inline-flex items-center gap-2 text-sm font-semibold text-[var(--color-text-link)] hover:underline">
      <ArrowLeftIcon class="size-4" />
      Campaigns
    </Link>

    <PageHeader
      title="Create a campaign"
      description="Describe the campaign you want. Atlas grounds it in your Business Brain, writes the copy, and generates supporting imagery. Add your own assets if you have them. Nothing publishes until you approve it."
      :icon="MegaphoneIcon"
    />

    <form class="space-y-6" @submit.prevent="submit">
      <Card>
        <h2 class="text-base font-semibold text-[var(--color-text-primary)]">1. Describe your campaign</h2>
        <label class="mt-3 block space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
          What do you want this campaign to do?
          <textarea
            v-model="form.objective"
            required
            minlength="20"
            maxlength="2000"
            rows="5"
            autofocus
            class="w-full rounded-[var(--radius-sm)] border-[var(--color-border)] text-base"
            placeholder="e.g. Invite past customers back for our fall service special — 15% off any detail package booked before October. Warm, appreciative tone, with a clear booking link."
          />
          <span class="flex items-center justify-between text-xs font-normal text-[var(--color-text-muted)]">
            <span>Be specific about the offer, timing, audience, and tone.</span>
            <span :class="objectiveReady ? 'text-[var(--color-text-muted)]' : 'text-amber-700'">{{ form.objective.trim().length }} / 20 min</span>
          </span>
          <span v-if="form.errors.objective" class="block text-xs text-rose-700">{{ form.errors.objective }}</span>
        </label>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
            Campaign title <span class="font-normal text-[var(--color-text-muted)]">(optional)</span>
            <input v-model="form.title" maxlength="255" class="w-full rounded-[var(--radius-sm)] border-[var(--color-border)]" placeholder="Fall customer appreciation" />
            <span class="block text-xs font-normal text-[var(--color-text-muted)]">Atlas generates one from your description if you leave this blank.</span>
            <span v-if="form.errors.title" class="block text-xs text-rose-700">{{ form.errors.title }}</span>
          </label>
          <label class="space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
            Primary goal
            <select v-model="form.goal" class="w-full rounded-[var(--radius-sm)] border-[var(--color-border)] bg-white">
              <option value="awareness">Build awareness</option>
              <option value="conversion">Drive action or sales</option>
              <option value="re_engagement">Re-engage customers</option>
            </select>
          </label>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
            Audience <span class="font-normal text-[var(--color-text-muted)]">(optional)</span>
            <textarea v-model="form.audience" maxlength="1000" rows="2" class="w-full rounded-[var(--radius-sm)] border-[var(--color-border)]" placeholder="Who should this reach?" />
          </label>
          <label class="space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
            Additional guidance <span class="font-normal text-[var(--color-text-muted)]">(optional)</span>
            <textarea v-model="form.guidance" maxlength="2000" rows="2" class="w-full rounded-[var(--radius-sm)] border-[var(--color-border)]" placeholder="Offer details, tone, constraints, or calls to action" />
          </label>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
            Starts <span class="font-normal text-[var(--color-text-muted)]">(optional)</span>
            <input v-model="form.starts_at" type="datetime-local" class="w-full rounded-[var(--radius-sm)] border-[var(--color-border)]" />
          </label>
          <label class="space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
            Ends <span class="font-normal text-[var(--color-text-muted)]">(optional)</span>
            <input v-model="form.ends_at" type="datetime-local" class="w-full rounded-[var(--radius-sm)] border-[var(--color-border)]" />
            <span v-if="form.errors.ends_at" class="block text-xs text-rose-700">{{ form.errors.ends_at }}</span>
          </label>
        </div>
      </Card>

      <Card padding="none" class="overflow-hidden">
        <details :open="assetsOpen" class="group" @toggle="assetsOpen = ($event.target as HTMLDetailsElement).open">
          <summary class="flex cursor-pointer items-center justify-between gap-3 px-5 py-4 text-sm font-semibold text-[var(--color-text-primary)] marker:content-none">
            <span>
              2. Use my own assets
              <span class="ml-1 font-normal text-[var(--color-text-muted)]">(optional)</span>
              <span v-if="selectedAssetCount > 0" class="ml-2 rounded-full bg-[var(--color-accent-50)] px-2 py-0.5 text-xs font-semibold text-[var(--color-accent-700)]">{{ selectedAssetCount }} selected</span>
            </span>
            <span class="text-xs font-normal text-[var(--color-text-muted)] group-open:hidden">Expand</span>
            <span class="hidden text-xs font-normal text-[var(--color-text-muted)] group-open:inline">Collapse</span>
          </summary>

          <div class="border-t border-[var(--color-border)] p-5">
            <p v-if="assets.length === 0" class="rounded-[var(--radius-sm)] bg-[var(--color-surface-panel)] p-3 text-sm text-[var(--color-text-secondary)]">
              No ready assets in your library yet. This is optional — Atlas will generate imagery for you.
              <Link href="/app/assets" class="font-semibold text-[var(--color-text-link)] hover:underline">Add sources →</Link>
            </p>

            <template v-else>
              <p class="text-sm text-[var(--color-text-muted)]">Select up to 20 ready assets. Atlas will use their Business Brain facts, descriptions, links, and imagery.</p>
              <div class="mt-4 grid gap-3 md:grid-cols-2">
                <label
                  v-for="asset in assets"
                  :key="asset.id"
                  class="overflow-hidden rounded-[var(--radius-md)] border bg-[var(--color-surface-elevated)] transition-colors"
                  :class="form.source_asset_ids.includes(asset.id) ? 'border-[var(--color-accent-500)] ring-2 ring-[var(--color-accent-100)]' : 'border-[var(--color-border)]'"
                >
                  <AssetMediaPreview
                    v-if="asset.media_url"
                    :url="asset.media_url"
                    :title="asset.title"
                    :mime-type="asset.media_mime_type"
                  />
                  <span class="flex cursor-pointer items-start gap-3 p-4">
                    <input v-model="form.source_asset_ids" type="checkbox" :value="asset.id" class="mt-1 rounded border-[var(--color-border)] text-[var(--color-accent-600)]" />
                    <span class="min-w-0">
                      <span class="block text-xs font-semibold uppercase tracking-[0.1em] text-[var(--color-text-muted)]">{{ assetLabels[asset.type] ?? asset.type }}</span>
                      <span class="mt-1 block font-semibold text-[var(--color-text-primary)]">{{ asset.title }}</span>
                      <span v-if="asset.description" class="mt-1 line-clamp-2 block text-sm text-[var(--color-text-secondary)]">{{ asset.description }}</span>
                    </span>
                  </span>
                </label>
              </div>
              <label class="mt-4 flex items-center gap-2 text-sm text-[var(--color-text-secondary)]">
                <input v-model="form.generate_imagery" type="checkbox" class="rounded border-[var(--color-border)] text-[var(--color-accent-600)]" />
                Also let Atlas generate a campaign image alongside my assets
              </label>
            </template>
            <p v-if="form.errors.source_asset_ids" class="mt-2 text-sm text-rose-700">{{ form.errors.source_asset_ids }}</p>
          </div>
        </details>
      </Card>

      <Card>
        <h2 class="text-base font-semibold text-[var(--color-text-primary)]">3. Choose draft channels</h2>
        <p class="mt-1 text-sm text-[var(--color-text-muted)]">Atlas prepares one draft per selected channel. You can change the channel selection again during review.</p>
        <div v-if="channels.length > 0" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <label
            v-for="channel in channels"
            :key="channel.id"
            class="flex cursor-pointer items-center gap-3 rounded-[var(--radius-sm)] border p-3"
            :class="form.channel_ids.includes(channel.id) ? 'border-[var(--color-accent-500)] bg-[var(--color-accent-50)]' : 'border-[var(--color-border)]'"
          >
            <input v-model="form.channel_ids" type="checkbox" :value="channel.id" class="rounded border-[var(--color-border)] text-[var(--color-accent-600)]" />
            <span>
              <span class="block text-sm font-semibold text-[var(--color-text-primary)]">{{ channelLabels[channel.type] ?? channel.type }}</span>
              <span class="block text-xs text-[var(--color-text-muted)]">{{ channel.name }}</span>
            </span>
          </label>
        </div>
        <p v-else class="mt-4 rounded-[var(--radius-sm)] bg-amber-50 p-3 text-sm text-amber-900">No active channels are available. Add or activate a channel before composing a campaign.</p>
        <p v-if="form.errors.channel_ids" class="mt-2 text-sm text-rose-700">{{ form.errors.channel_ids }}</p>
      </Card>

      <div class="flex flex-wrap items-center justify-between gap-4 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface-elevated)] p-4">
        <p class="text-sm text-[var(--color-text-secondary)]">
          Atlas prepares drafts only. You will review and approve before anything is queued.
        </p>
        <button
          type="submit"
          :disabled="!canSubmit"
          class="shrink-0 rounded-[var(--radius-sm)] bg-[var(--color-accent-600)] px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
        >
          {{ form.processing ? 'Preparing campaign… writing copy and generating imagery (up to a minute)' : 'Prepare campaign' }}
        </button>
      </div>
    </form>
  </div>
</template>
