<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'

defineOptions({ layout: AuthLayout })

const props = defineProps<{
  invitation: { company_name: string; email: string; role: string; expires_at: string }
  token: string
  signed_in_email: string
}>()

const form = useForm({})
const emailMatches = props.signed_in_email.toLowerCase() === props.invitation.email.toLowerCase()
const invitationError = computed(() => (form.errors as Record<string, string>).invitation)

function accept(): void {
  form.post(`/team-invitations/${props.token}`)
}
</script>

<template>
  <Head><title>Team invitation — Atlas</title></Head>

  <div class="w-full max-w-md rounded-xl border border-[var(--color-border)] bg-white p-6 shadow-sm">
    <p class="text-xs font-medium uppercase tracking-widest text-[var(--color-text-muted)]">Team invitation</p>
    <h1 class="mt-2 text-2xl font-semibold text-[var(--color-text-primary)]">Join {{ invitation.company_name }}</h1>
    <p class="mt-3 text-sm text-[var(--color-text-secondary)]">
      You were invited as a <strong>{{ invitation.role }}</strong> using {{ invitation.email }}.
    </p>

    <div v-if="!emailMatches" class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
      You are signed in as {{ signed_in_email }}. Sign out and use {{ invitation.email }} to accept this invitation.
    </div>
    <p v-if="invitationError" class="mt-4 text-sm text-rose-600">{{ invitationError }}</p>

    <button
      type="button"
      :disabled="form.processing || !emailMatches"
      class="mt-6 w-full rounded-lg bg-[var(--color-accent-500)] px-4 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60"
      @click="accept"
    >
      {{ form.processing ? 'Joining…' : 'Accept invitation' }}
    </button>
    <Link href="/app" class="mt-3 block text-center text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)]">
      Return to Atlas
    </Link>
  </div>
</template>
