<script setup lang="ts">
import { computed } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import type { SharedProps } from '@/types'

const page = usePage<SharedProps>()
const status = computed(() => page.props.flash.success)

const form = useForm({
  email: '',
})

function submit(): void {
  form.post('/forgot-password')
}
</script>

<template>
  <Head><title>Reset your password — Atlas</title></Head>
  <AuthLayout>
    <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1">Reset your password</h1>
    <p class="text-sm text-[var(--color-text-muted)] mb-5">Enter your email and we'll send you a link to choose a new password.</p>

    <div
      v-if="status"
      class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm"
      role="status"
    >
      {{ status }}
    </div>

    <form class="space-y-4" @submit.prevent="submit">
      <div>
        <label for="email" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Email</label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          autocomplete="email"
          required
          :class="[
            'w-full px-3 py-2 text-sm rounded-lg border bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] placeholder-[var(--color-text-placeholder)] transition-colors duration-[var(--duration-fast)]',
            form.errors.email
              ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
              : 'border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]',
          ]"
          placeholder="you@example.com"
        />
        <p v-if="form.errors.email" class="mt-1 text-xs text-rose-600">{{ form.errors.email }}</p>
      </div>

      <button
        type="submit"
        :disabled="form.processing"
        class="w-full py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
      >
        {{ form.processing ? 'Sending…' : 'Send reset link' }}
      </button>
    </form>

    <p class="mt-5 text-center text-sm text-[var(--color-text-muted)]">
      Remembered it?
      <a href="/login" class="text-[var(--color-text-link)] hover:underline">Back to sign in</a>
    </p>
  </AuthLayout>
</template>
