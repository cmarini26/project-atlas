<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import { UserGroupIcon } from '@heroicons/vue/24/outline'

defineOptions({ layout: AppLayout })

interface Member {
  id: string
  name: string
  email: string
  role: string
  joined_at: string | null
  can_manage: boolean
}

interface Invitation {
  id: string
  email: string
  role: string
  expires_at: string
  can_manage: boolean
}

const props = defineProps<{
  members: Member[]
  pending_invitations: Invitation[]
  actor_role: string
  allowed_invitation_roles: string[]
}>()

const inviteForm = useForm({ email: '', role: props.allowed_invitation_roles.includes('member') ? 'member' : props.allowed_invitation_roles[0] })

function invite(): void {
  inviteForm.post('/app/settings/team/invitations', {
    preserveScroll: true,
    onSuccess: () => inviteForm.reset('email'),
  })
}

function updateRole(member: Member, role: string): void {
  router.patch(`/app/settings/team/members/${member.id}`, { role }, { preserveScroll: true })
}

function removeMember(member: Member): void {
  if (window.confirm(`Remove ${member.name} from this workspace?`)) {
    router.delete(`/app/settings/team/members/${member.id}`, { preserveScroll: true })
  }
}

function revokeInvitation(invitation: Invitation): void {
  router.delete(`/app/settings/team/invitations/${invitation.id}`, { preserveScroll: true })
}
</script>

<template>
  <Head><title>Team — Atlas</title></Head>
  <div class="max-w-3xl">
    <Link href="/app/settings" class="mb-4 inline-flex text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)]">← Settings</Link>
    <PageHeader title="Team" description="Invite colleagues and control access to this Atlas workspace." :icon="UserGroupIcon" />

    <section class="mb-6 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-elevated)] p-5">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Invite a team member</h2>
      <p class="mt-1 text-xs text-[var(--color-text-muted)]">Invitations expire after seven days and must be accepted by the invited email.</p>
      <form class="mt-4 grid gap-3 sm:grid-cols-[1fr_150px_auto]" @submit.prevent="invite">
        <div>
          <label for="team-email" class="sr-only">Email address</label>
          <input id="team-email" v-model="inviteForm.email" type="email" required placeholder="teammate@example.com" class="w-full rounded-lg border border-[var(--color-border)] bg-white px-3 py-2 text-sm" />
          <p v-if="inviteForm.errors.email" class="mt-1 text-xs text-rose-600">{{ inviteForm.errors.email }}</p>
        </div>
        <div>
          <label for="team-role" class="sr-only">Role</label>
          <select id="team-role" v-model="inviteForm.role" class="w-full rounded-lg border border-[var(--color-border)] bg-white px-3 py-2 text-sm capitalize">
            <option v-for="role in allowed_invitation_roles" :key="role" :value="role">{{ role }}</option>
          </select>
          <p v-if="inviteForm.errors.role" class="mt-1 text-xs text-rose-600">{{ inviteForm.errors.role }}</p>
        </div>
        <button type="submit" :disabled="inviteForm.processing" class="rounded-lg bg-[var(--color-accent-500)] px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
          {{ inviteForm.processing ? 'Sending…' : 'Send invite' }}
        </button>
      </form>
    </section>

    <section class="mb-6 overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-elevated)]">
      <div class="border-b border-[var(--color-border)] px-5 py-4">
        <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Members ({{ members.length }})</h2>
      </div>
      <div class="divide-y divide-[var(--color-border)]">
        <div v-for="member in members" :key="member.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-[var(--color-text-primary)]">{{ member.name }}</p>
            <p class="truncate text-xs text-[var(--color-text-muted)]">{{ member.email }}</p>
          </div>
          <Badge v-if="!member.can_manage" variant="accent" class="capitalize">{{ member.role }}</Badge>
          <select v-else :value="member.role" class="rounded-lg border border-[var(--color-border)] bg-white px-3 py-1.5 text-sm capitalize" @change="updateRole(member, ($event.target as HTMLSelectElement).value)">
            <option v-if="actor_role === 'owner'" value="admin">admin</option>
            <option value="member">member</option>
            <option value="viewer">viewer</option>
          </select>
          <button v-if="member.can_manage" type="button" class="text-xs font-medium text-rose-600 hover:text-rose-700" @click="removeMember(member)">Remove</button>
        </div>
      </div>
    </section>

    <section v-if="pending_invitations.length" class="overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-elevated)]">
      <div class="border-b border-[var(--color-border)] px-5 py-4">
        <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Pending invitations ({{ pending_invitations.length }})</h2>
      </div>
      <div class="divide-y divide-[var(--color-border)]">
        <div v-for="invitation in pending_invitations" :key="invitation.id" class="flex items-center gap-3 px-5 py-4">
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-[var(--color-text-primary)]">{{ invitation.email }}</p>
            <p class="text-xs capitalize text-[var(--color-text-muted)]">{{ invitation.role }} · expires {{ new Date(invitation.expires_at).toLocaleDateString() }}</p>
          </div>
          <button v-if="invitation.can_manage" type="button" class="text-xs font-medium text-rose-600" @click="revokeInvitation(invitation)">Revoke</button>
        </div>
      </div>
    </section>
  </div>
</template>
