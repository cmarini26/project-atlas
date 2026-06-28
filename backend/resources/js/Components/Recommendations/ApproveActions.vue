<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps<{
  recommendationId: string
}>()

const emit = defineEmits<{
  editAndApprove: []
}>()

const showRejectNote = ref(false)
const rejectNote = ref('')
const approveError = ref<string | null>(null)
const rejectError = ref<string | null>(null)

const approveForm = useForm({})
const rejectForm = useForm({ notes: '' })

function approve(): void {
  approveError.value = null
  approveForm.post(`/app/recommendations/${props.recommendationId}/approve`, {
    preserveScroll: true,
    onError: () => {
      approveError.value = 'Something went wrong. Please try again.'
    },
  })
}

function reject(): void {
  rejectError.value = null
  rejectForm.notes = rejectNote.value
  rejectForm.post(`/app/recommendations/${props.recommendationId}/reject`, {
    preserveScroll: true,
    onError: () => {
      rejectError.value = 'Something went wrong. Please try again.'
    },
  })
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col sm:flex-row gap-3">
      <button
        type="button"
        :disabled="approveForm.processing || rejectForm.processing"
        class="flex-1 py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-600)] text-white hover:bg-[var(--color-accent-700)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        @click="approve"
      >
        {{ approveForm.processing ? 'Approving…' : 'Approve' }}
      </button>

      <button
        type="button"
        :disabled="approveForm.processing || rejectForm.processing"
        class="flex-1 py-2.5 px-4 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        @click="emit('editAndApprove')"
      >
        Edit &amp; Approve
      </button>

      <button
        type="button"
        :disabled="approveForm.processing || rejectForm.processing"
        class="flex-1 py-2.5 px-4 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-muted)] hover:bg-[var(--color-surface-subtle)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        @click="showRejectNote = !showRejectNote"
      >
        Not this time
      </button>
    </div>

    <p v-if="approveError" class="text-sm text-rose-600" role="alert">{{ approveError }}</p>

    <p class="text-xs text-[var(--color-text-muted)]">
      Approving queues this content for publishing. You can make edits before approving if anything needs changing.
    </p>

    <div v-if="showRejectNote" class="space-y-2">
      <textarea
        v-model="rejectNote"
        rows="2"
        class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] resize-none focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)]"
        placeholder="Optional: tell Atlas why (helps it learn)"
      />
      <p v-if="rejectError" class="text-sm text-rose-600" role="alert">{{ rejectError }}</p>
      <button
        type="button"
        :disabled="rejectForm.processing"
        class="w-full py-2 px-4 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        @click="reject"
      >
        {{ rejectForm.processing ? 'Passing…' : 'Confirm: not this time' }}
      </button>
    </div>
  </div>
</template>
