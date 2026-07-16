<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import { UsersIcon } from '@heroicons/vue/24/outline'

// Persistent layout: the sidebar/toast shell survives Inertia visits.
defineOptions({ layout: AppLayout })

interface AudienceData {
  id: string
  name: string
  status: string
}

interface MemberRow {
  id: string
  email: string
  display_name: string | null
  status: string
}

const props = defineProps<{
  audience: AudienceData
  members: MemberRow[]
}>()

const form = useForm({
  email: '',
  display_name: '',
})

function addMember(): void {
  form.post(`/app/settings/email/audiences/${props.audience.id}/members`, {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  })
}

function removeMember(member: MemberRow): void {
  router.delete(`/app/settings/email/audiences/${props.audience.id}/members/${member.id}`, { preserveScroll: true })
}
</script>

<template>
  <Head><title>{{ audience.name }} — Atlas</title></Head>
  <div class="max-w-2xl">
    <div class="flex items-start gap-3 mb-6">
      <Link href="/app/settings/email/audiences" class="mt-1 text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)] transition-colors duration-[var(--duration-fast)]" aria-label="Back">
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
      </Link>
      <PageHeader :title="audience.name" description="Manage who belongs to this audience." :icon="UsersIcon" />
    </div>

    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-4">Add a contact</h2>
      <form class="space-y-3" @submit.prevent="addMember">
        <div>
          <label for="member-email" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Email</label>
          <input
            id="member-email"
            v-model="form.email"
            type="email"
            placeholder="someone@example.com"
            required
            class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)] transition-colors duration-[var(--duration-fast)]"
          />
          <p v-if="form.errors.email" class="mt-1 text-xs text-rose-600">{{ form.errors.email }}</p>
        </div>
        <div>
          <label for="member-name" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Name <span class="normal-case text-[var(--color-text-placeholder)]">(optional)</span></label>
          <input
            id="member-name"
            v-model="form.display_name"
            type="text"
            class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)] transition-colors duration-[var(--duration-fast)]"
          />
        </div>
        <button
          type="submit"
          :disabled="form.processing"
          class="py-2 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        >
          {{ form.processing ? 'Adding…' : 'Add contact' }}
        </button>
      </form>
    </div>

    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-4">
        Members <span class="text-[var(--color-text-muted)] font-normal">({{ members.length }})</span>
      </h2>

      <div v-if="members.length > 0" class="divide-y divide-[var(--color-border)]">
        <div v-for="member in members" :key="member.id" class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-[var(--color-text-primary)]">{{ member.display_name || member.email }}</p>
            <p v-if="member.display_name" class="text-xs text-[var(--color-text-muted)]">{{ member.email }}</p>
          </div>
          <Badge v-if="member.status !== 'active'" variant="muted">{{ member.status }}</Badge>
          <button
            type="button"
            class="shrink-0 py-1.5 px-3 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
            @click="removeMember(member)"
          >
            Remove
          </button>
        </div>
      </div>

      <div v-else class="text-center py-4">
        <p class="text-sm text-[var(--color-text-muted)]">No contacts yet.</p>
        <p class="text-xs text-[var(--color-text-muted)] mt-1">Add one above.</p>
      </div>
    </div>
  </div>
</template>
