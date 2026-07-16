<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import { UsersIcon } from '@heroicons/vue/24/outline'

// Persistent layout: the sidebar/toast shell survives Inertia visits.
defineOptions({ layout: AppLayout })

interface AudienceRow {
  id: string
  name: string
  status: string
  member_count: number
}

defineProps<{
  audiences: AudienceRow[]
}>()

const form = useForm({
  name: '',
})

function createAudience(): void {
  form.post('/app/settings/email/audiences', {
    onSuccess: () => form.reset(),
  })
}

function archiveAudience(audience: AudienceRow): void {
  router.patch(`/app/settings/email/audiences/${audience.id}`, { archived: true }, { preserveScroll: true })
}
</script>

<template>
  <Head><title>Email Audiences — Atlas</title></Head>
  <div class="max-w-2xl">
    <PageHeader
      title="Email Audiences"
      description="Named groups of contacts an Email campaign can target."
      :icon="UsersIcon"
    />

    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-4">Create an audience</h2>
      <form class="flex items-start gap-2" @submit.prevent="createAudience">
        <div class="flex-1">
          <input
            id="audience-name"
            v-model="form.name"
            type="text"
            placeholder="e.g. Newsletter subscribers"
            required
            class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)] transition-colors duration-[var(--duration-fast)]"
          />
          <p v-if="form.errors.name" class="mt-1 text-xs text-rose-600">{{ form.errors.name }}</p>
        </div>
        <button
          type="submit"
          :disabled="form.processing"
          class="shrink-0 py-2 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        >
          {{ form.processing ? 'Creating…' : 'Create' }}
        </button>
      </form>
    </div>

    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-4">Audiences</h2>

      <div v-if="audiences.length > 0" class="divide-y divide-[var(--color-border)]">
        <div v-for="audience in audiences" :key="audience.id" class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-0.5">
              <Link :href="`/app/settings/email/audiences/${audience.id}`" class="text-sm font-medium text-[var(--color-text-primary)] hover:underline">
                {{ audience.name }}
              </Link>
              <Badge :variant="audience.status === 'active' ? 'success' : 'muted'">{{ audience.status }}</Badge>
            </div>
            <p class="text-xs text-[var(--color-text-muted)]">
              {{ audience.member_count }} contact{{ audience.member_count === 1 ? '' : 's' }}
              <span v-if="audience.member_count === 0" class="text-amber-600">— empty audience</span>
            </p>
          </div>
          <button
            v-if="audience.status === 'active'"
            type="button"
            class="shrink-0 py-1.5 px-3 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
            @click="archiveAudience(audience)"
          >
            Archive
          </button>
        </div>
      </div>

      <div v-else class="text-center py-4">
        <p class="text-sm text-[var(--color-text-muted)]">No audiences yet.</p>
        <p class="text-xs text-[var(--color-text-muted)] mt-1">Create one above to start collecting contacts for Email campaigns.</p>
      </div>
    </div>
  </div>
</template>
