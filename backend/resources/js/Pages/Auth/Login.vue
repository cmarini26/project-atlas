<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'

const form = useForm({
  email: '',
  password: '',
  remember: false,
})

function submit(): void {
  form.post('/login', {
    onFinish: () => form.reset('password'),
  })
}
</script>

<template>
  <AuthLayout>
    <h1 class="text-base font-semibold text-[--color-text-primary] mb-5">Sign in</h1>

    <form class="space-y-4" @submit.prevent="submit">
      <div>
        <label for="email" class="block text-sm font-medium text-[--color-text-secondary] mb-1.5">Email</label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          autocomplete="email"
          required
          :class="[
            'w-full px-3 py-2 text-sm rounded-lg border bg-[--color-surface-elevated] text-[--color-text-primary] placeholder-[--color-text-placeholder] transition-colors duration-[--duration-fast]',
            form.errors.email
              ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
              : 'border-[--color-border] focus:outline-none focus:ring-1 focus:ring-[--color-border-focus] focus:border-[--color-border-focus]',
          ]"
          placeholder="you@example.com"
        />
        <p v-if="form.errors.email" class="mt-1 text-xs text-rose-600">{{ form.errors.email }}</p>
      </div>

      <div>
        <label for="password" class="block text-sm font-medium text-[--color-text-secondary] mb-1.5">Password</label>
        <input
          id="password"
          v-model="form.password"
          type="password"
          autocomplete="current-password"
          required
          :class="[
            'w-full px-3 py-2 text-sm rounded-lg border bg-[--color-surface-elevated] text-[--color-text-primary] transition-colors duration-[--duration-fast]',
            form.errors.password
              ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
              : 'border-[--color-border] focus:outline-none focus:ring-1 focus:ring-[--color-border-focus] focus:border-[--color-border-focus]',
          ]"
          placeholder="••••••••"
        />
        <p v-if="form.errors.password" class="mt-1 text-xs text-rose-600">{{ form.errors.password }}</p>
      </div>

      <button
        type="submit"
        :disabled="form.processing"
        class="w-full py-2.5 px-4 text-sm font-medium rounded-lg bg-[--color-accent-600] text-white hover:bg-[--color-accent-700] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[--duration-fast]"
      >
        {{ form.processing ? 'Signing in…' : 'Sign in' }}
      </button>
    </form>

    <p class="mt-5 text-center text-sm text-[--color-text-muted]">
      Don't have an account?
      <a href="/register" class="text-[--color-text-link] hover:underline">Create one</a>
    </p>
  </AuthLayout>
</template>
