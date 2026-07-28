<script setup lang="ts">
import { DocumentIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{
  url: string
  title: string
  mimeType?: string | null
}>()

function inferredKind(): 'image' | 'video' | 'document' {
  if (props.mimeType?.startsWith('image/')) return 'image'
  if (props.mimeType?.startsWith('video/')) return 'video'
  if (props.mimeType) return 'document'

  const pathname = props.url.split(/[?#]/, 1)[0].toLowerCase()
  if (/\.(jpe?g|png|webp|gif)$/.test(pathname)) return 'image'
  if (/\.(mp4|mov)$/.test(pathname)) return 'video'

  return 'document'
}
</script>

<template>
  <img
    v-if="inferredKind() === 'image'"
    :src="url"
    :alt="title"
    class="h-44 w-full object-cover"
  />
  <video
    v-else-if="inferredKind() === 'video'"
    :src="url"
    :aria-label="`${title} video`"
    class="h-44 w-full bg-black object-contain"
    controls
    preload="metadata"
  />
  <a
    v-else
    :href="url"
    target="_blank"
    rel="noopener noreferrer"
    class="flex h-44 w-full flex-col items-center justify-center gap-2 bg-[var(--color-surface-subtle)] px-5 text-center text-sm font-semibold text-[var(--color-text-link)] hover:underline"
  >
    <DocumentIcon class="size-9 text-[var(--color-text-muted)]" />
    Open {{ mimeType === 'application/pdf' || url.toLowerCase().includes('.pdf') ? 'PDF' : 'uploaded file' }}
  </a>
</template>
