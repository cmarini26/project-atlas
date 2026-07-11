<script setup lang="ts">
import { useScrollReveal } from '@/composables/useScrollReveal'
import ScoreBar from './ScoreBar.vue'

interface Quadrant {
  label: string
  body: string
}

withDefaults(
  defineProps<{
    title: string
    channels?: string
    quadrants: Quadrant[]
    confidence: number
    caption: string
    draftLabel?: string
    draftText?: string
    variant?: 'compact' | 'full'
  }>(),
  {
    channels: undefined,
    draftLabel: undefined,
    draftText: undefined,
    variant: 'full',
  },
)

const { target, isVisible } = useScrollReveal()
</script>

<template>
  <figure ref="target" class="m-0">
    <div
      :class="[
        'rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-elevated)] shadow-[0_8px_30px_rgb(0,0,0,0.08)]',
        variant === 'full' ? 'p-6 sm:p-8' : 'p-5 sm:p-6',
      ]"
    >
      <div class="flex items-center gap-2 mb-4">
        <span class="size-2 rounded-full bg-[var(--color-accent-500)]" aria-hidden="true" />
        <span class="text-label-sm uppercase tracking-[0.06em] text-[var(--color-text-muted)]">Awaiting review</span>
      </div>

      <h3
        :class="[
          'text-[var(--color-text-primary)] font-semibold',
          variant === 'full' ? 'text-heading-2' : 'text-heading-3',
        ]"
      >
        {{ title }}
        <span v-if="channels" class="font-normal text-[var(--color-text-muted)] text-body">
          — {{ channels }}
        </span>
      </h3>

      <div class="my-5 border-t border-[var(--color-border)]" />

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div
          v-for="(quadrant, index) in quadrants"
          :key="quadrant.label"
          :class="[
            'rounded-lg bg-[var(--color-surface-subtle)] p-5 transition-all duration-300 ease-[var(--ease-out)]',
            isVisible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2',
          ]"
          :style="{ transitionDelay: isVisible ? `${index * 60}ms` : '0ms' }"
        >
          <p class="text-label uppercase tracking-[0.06em] text-[var(--color-text-muted)] mb-2">
            {{ quadrant.label }}
          </p>
          <p class="text-body-lg text-[var(--color-text-secondary)]">{{ quadrant.body }}</p>
        </div>
      </div>

      <div class="mt-5">
        <ScoreBar label="Confidence score" :value="confidence" :reveal="isVisible" />
      </div>

      <template v-if="variant === 'full' && draftText">
        <div class="my-5 border-t border-[var(--color-border)]" />
        <div class="rounded-lg border border-[var(--color-border)] p-5">
          <p class="text-label uppercase tracking-[0.06em] text-[var(--color-text-muted)] mb-2">
            {{ draftLabel ?? 'Draft content' }}
          </p>
          <p class="text-body text-[var(--color-text-secondary)] italic">&ldquo;{{ draftText }}&rdquo;</p>
        </div>
      </template>

      <div class="my-5 border-t border-[var(--color-border)]" />

      <div
        :class="[
          'flex flex-col sm:flex-row gap-3 transition-opacity duration-300',
          isVisible ? 'opacity-100' : 'opacity-0',
        ]"
        :style="{ transitionDelay: isVisible ? '300ms' : '0ms' }"
      >
        <span class="inline-flex items-center justify-center rounded-lg bg-[var(--color-accent-500)] text-white h-10 px-4 text-body font-medium">
          Approve
        </span>
        <span class="inline-flex items-center justify-center rounded-lg border-[1.5px] border-[var(--color-accent-500)] text-[var(--color-accent-600)] h-10 px-4 text-body font-medium">
          Edit &amp; Approve
        </span>
        <span class="inline-flex items-center justify-center rounded-lg text-[var(--color-text-muted)] h-10 px-4 text-body font-medium">
          Not this time
        </span>
      </div>
    </div>
    <figcaption class="sr-only">{{ caption }}</figcaption>
  </figure>
</template>
