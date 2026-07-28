<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AssetMediaPreview from '@/Components/Assets/AssetMediaPreview.vue'
import Badge from '@/Components/UI/Badge.vue'
import Card from '@/Components/UI/Card.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import { ArrowPathIcon, LinkIcon, PencilSquareIcon, PhotoIcon, PlusIcon, RectangleStackIcon, TrashIcon, XMarkIcon } from '@heroicons/vue/24/outline'

defineOptions({ layout: AppLayout })

type SourceAsset = {
  id: string
  type: string
  title: string
  description: string | null
  source_url: string | null
  media_url: string | null
  media_mime_type: string | null
  status: string
  processing_error: string | null
  starts_at: string | null
  ends_at: string | null
  processed_at: string | null
  opportunities_count: number
}

const props = defineProps<{ assets: SourceAsset[]; types: string[] }>()
const showForm = ref(false)
const editingAsset = ref<SourceAsset | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const form = useForm({
  type: 'product_service',
  title: '',
  description: '',
  source_url: '',
  media: null as File | null,
  starts_at: '',
  ends_at: '',
})

const labels: Record<string, string> = {
  product_service: 'Product or service',
  promotion_event: 'Promotion or event',
  photo_video: 'Photo or video',
  document_case_study: 'Document or case study',
  webpage_blog_post: 'Webpage or blog post',
  brand_material: 'Brand material',
}

const statusVariant: Record<string, 'success' | 'accent' | 'warning' | 'muted'> = {
  ready: 'success',
  processing: 'accent',
  failed: 'warning',
  archived: 'muted',
}

function submit(): void {
  const options = {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      form.type = 'product_service'
      if (fileInput.value) fileInput.value.value = ''
      showForm.value = false
      editingAsset.value = null
    },
  }

  if (editingAsset.value) {
    form.transform((data) => ({ ...data, _method: 'put' })).post(`/app/assets/${editingAsset.value.id}`, options)
    return
  }

  form.post('/app/assets', options)
}

function beginEdit(asset: SourceAsset): void {
  editingAsset.value = asset
  form.type = asset.type
  form.title = asset.title
  form.description = asset.description ?? ''
  form.source_url = asset.source_url ?? ''
  form.media = null
  form.starts_at = asset.starts_at?.slice(0, 16) ?? ''
  form.ends_at = asset.ends_at?.slice(0, 16) ?? ''
  showForm.value = true
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function closeForm(): void {
  form.reset()
  form.type = 'product_service'
  editingAsset.value = null
  showForm.value = false
}

function retry(asset: SourceAsset): void {
  router.post(`/app/assets/${asset.id}/retry`, {}, { preserveScroll: true })
}

function archive(asset: SourceAsset): void {
  if (window.confirm(`Archive “${asset.title}”? Existing recommendations will remain available.`)) {
    router.delete(`/app/assets/${asset.id}`, { preserveScroll: true })
  }
}

function dateLabel(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : ''
}
</script>

<template>
  <Head><title>Asset Library — Atlas</title></Head>

  <div class="max-w-6xl space-y-6">
    <PageHeader
      title="Asset Library"
      description="Give Atlas new products, offers, stories, and creative material. Atlas turns each source into Business Brain knowledge and a campaign opportunity for your approval."
      :icon="RectangleStackIcon"
    >
      <template #actions>
        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-[var(--radius-sm)] bg-[var(--color-accent-600)] px-4 py-2 text-sm font-semibold text-white hover:bg-[var(--color-accent-700)]"
          @click="showForm ? closeForm() : showForm = true"
        >
          <XMarkIcon v-if="showForm" class="size-4" />
          <PlusIcon v-else class="size-4" />
          {{ showForm ? 'Close' : 'Add asset' }}
        </button>
      </template>
    </PageHeader>

    <Card v-if="showForm" class="border-[var(--color-accent-200)]">
      <form class="space-y-5" @submit.prevent="submit">
        <div>
          <h2 class="text-base font-semibold text-[var(--color-text-primary)]">{{ editingAsset ? 'Edit source asset' : 'Add a source asset' }}</h2>
          <p class="mt-1 text-sm text-[var(--color-text-muted)]">Provide the context Atlas needs to recommend a useful campaign.</p>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          <label class="space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
            Asset type
            <select v-model="form.type" class="w-full rounded-[var(--radius-sm)] border-[var(--color-border)] bg-white">
              <option v-for="type in types" :key="type" :value="type">{{ labels[type] ?? type }}</option>
            </select>
          </label>
          <label class="space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
            Title
            <input v-model="form.title" required maxlength="255" class="w-full rounded-[var(--radius-sm)] border-[var(--color-border)]" placeholder="Summer service package" />
            <span v-if="form.errors.title" class="block text-xs text-rose-700">{{ form.errors.title }}</span>
          </label>
        </div>

        <label class="block space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
          Description
          <textarea v-model="form.description" rows="4" class="w-full rounded-[var(--radius-sm)] border-[var(--color-border)]" placeholder="What is it, who is it for, and why does it matter now?" />
        </label>

        <div class="grid gap-4 md:grid-cols-2">
          <label class="space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
            Source URL
            <input v-model="form.source_url" type="url" class="w-full rounded-[var(--radius-sm)] border-[var(--color-border)]" placeholder="https://…" />
          </label>
          <label class="space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
            Optional file (10 MB max)
            <input ref="fileInput" type="file" accept="image/*,video/mp4,video/quicktime,application/pdf" class="block w-full text-sm" @change="form.media = ($event.target as HTMLInputElement).files?.[0] ?? null" />
          </label>
          <label class="space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
            Starts
            <input v-model="form.starts_at" type="datetime-local" class="w-full rounded-[var(--radius-sm)] border-[var(--color-border)]" />
          </label>
          <label class="space-y-1.5 text-sm font-medium text-[var(--color-text-secondary)]">
            Ends
            <input v-model="form.ends_at" type="datetime-local" class="w-full rounded-[var(--radius-sm)] border-[var(--color-border)]" />
          </label>
        </div>

        <div class="flex justify-end">
          <button :disabled="form.processing" class="rounded-[var(--radius-sm)] bg-[var(--color-accent-600)] px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-60">
            {{ form.processing ? 'Saving…' : editingAsset ? 'Save and reanalyze' : 'Add and analyze' }}
          </button>
        </div>
      </form>
    </Card>

    <EmptyState
      v-if="assets.length === 0 && !showForm"
      title="Your Asset Library is empty"
      description="Add a product, offer, event, photo, case study, webpage, or brand asset. Atlas will analyze it and suggest what to do next."
      variant="info"
    >
      <template #icon><PhotoIcon class="size-6" /></template>
      <template #action>
        <button class="text-sm font-semibold text-[var(--color-text-link)] hover:underline" @click="showForm = true">Add your first asset →</button>
      </template>
    </EmptyState>

    <section v-else-if="assets.length > 0" class="grid gap-4 md:grid-cols-2">
      <Card v-for="asset in assets" :key="asset.id" padding="none" class="overflow-hidden">
        <AssetMediaPreview
          v-if="asset.media_url"
          :url="asset.media_url"
          :title="asset.title"
          :mime-type="asset.media_mime_type"
        />
        <div class="p-5">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <Badge variant="muted">{{ labels[asset.type] ?? asset.type }}</Badge>
              <h2 class="mt-3 text-base font-semibold text-[var(--color-text-primary)]">{{ asset.title }}</h2>
            </div>
            <Badge :variant="statusVariant[asset.status] ?? 'muted'">{{ asset.status }}</Badge>
          </div>
          <p v-if="asset.description" class="mt-2 line-clamp-3 text-sm leading-6 text-[var(--color-text-secondary)]">{{ asset.description }}</p>
          <div v-if="asset.starts_at || asset.ends_at" class="mt-3 text-xs text-[var(--color-text-muted)]">
            {{ dateLabel(asset.starts_at) }}{{ asset.starts_at && asset.ends_at ? ' – ' : '' }}{{ dateLabel(asset.ends_at) }}
          </div>
          <a v-if="asset.source_url" :href="asset.source_url" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex items-center gap-1 text-sm text-[var(--color-text-link)] hover:underline">
            <LinkIcon class="size-4" /> View source
          </a>
          <p v-if="asset.status === 'ready'" class="mt-4 rounded-[var(--radius-sm)] bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800">
            {{ asset.opportunities_count }} campaign opportunity{{ asset.opportunities_count === 1 ? '' : 'ies' }} linked to this source
          </p>
          <p v-if="asset.processing_error" class="mt-4 rounded-[var(--radius-sm)] bg-rose-50 px-3 py-2 text-xs text-rose-800">{{ asset.processing_error }}</p>
          <div class="mt-4 flex justify-end gap-2 border-t border-[var(--color-border)] pt-4">
            <button class="inline-flex items-center gap-1.5 text-sm font-semibold text-[var(--color-text-link)]" @click="beginEdit(asset)">
              <PencilSquareIcon class="size-4" /> Edit
            </button>
            <button v-if="asset.status === 'failed'" class="inline-flex items-center gap-1.5 text-sm font-semibold text-[var(--color-text-link)]" @click="retry(asset)">
              <ArrowPathIcon class="size-4" /> Retry
            </button>
            <button class="inline-flex items-center gap-1.5 text-sm text-[var(--color-text-muted)] hover:text-rose-700" @click="archive(asset)">
              <TrashIcon class="size-4" /> Archive
            </button>
          </div>
        </div>
      </Card>
    </section>
  </div>
</template>
