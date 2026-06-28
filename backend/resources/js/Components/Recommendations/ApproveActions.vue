<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps<{
  recommendationId: string
}>()

const showRejectNote = ref(false)
const rejectNote = ref('')

const approveForm = useForm({})
const rejectForm = useForm({ notes: '' })

function approve(): void {
  approveForm.post(`/app/recommendations/${props.recommendationId}/approve`, {
    preserveScroll: true,
  })
}

function reject(): void {
  rejectForm.notes = rejectNote.value
  rejectForm.post(`/app/recommendations/${props.recommendationId}/reject`, {
    preserveScroll: true,
  })
}
</script>

<template>
  <div class="space-y-3">
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
        @click="showRejectNote = !showRejectNote"
      >
        Not this time
      </button>
    </div>

    <div v-if="showRejectNote" class="space-y-2">
      <textarea
        v-model="rejectNote"
        rows="2"
        class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] resize-none focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)]"
        placeholder="Optional: tell Atlas why (helps it learn)"
      />
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
