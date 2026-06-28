<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'

interface CompanyOption {
  id: string
  name: string
  industry: string | null
  role: string
}

defineProps<{
  companies: CompanyOption[]
}>()

const form = useForm({ company_id: '' })

function select(id: string): void {
  form.company_id = id
  form.post('/company/select')
}
</script>

<template>
  <AuthLayout>
    <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1">Select a workspace</h1>
    <p class="text-sm text-[var(--color-text-muted)] mb-5">You have access to multiple companies.</p>

    <div class="space-y-2">
      <button
        v-for="company in companies"
        :key="company.id"
        type="button"
        class="w-full flex items-center gap-3 px-4 py-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] hover:border-[var(--color-accent-400)] hover:bg-[var(--color-accent-50)] text-left transition-colors duration-[var(--duration-fast)]"
        :disabled="form.processing"
        @click="select(company.id)"
      >
        <div class="size-9 rounded-lg bg-[var(--color-surface-subtle)] flex items-center justify-center shrink-0">
          <span class="text-sm font-semibold text-[var(--color-text-secondary)]">{{ company.name[0]?.toUpperCase() }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-[var(--color-text-primary)] truncate">{{ company.name }}</p>
          <p class="text-xs text-[var(--color-text-muted)] truncate">{{ company.industry ?? 'No industry set' }} · {{ company.role }}</p>
        </div>
        <svg class="size-4 shrink-0 text-[var(--color-text-placeholder)]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
      </button>
    </div>

    <div v-if="companies.length === 0" class="text-center py-4">
      <p class="text-sm text-[var(--color-text-muted)]">No workspaces found.</p>
      <a href="/onboarding" class="mt-2 inline-block text-sm text-[var(--color-text-link)] hover:underline">Set one up</a>
    </div>
  </AuthLayout>
</template>
