<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'

const form = useForm({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})

function submit(): void {
  form.post('/register', {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}
</script>

<template>
  <AuthLayout>
    <h1 class="text-base font-semibold text-[--color-text-primary] mb-5">Create account</h1>

    <form class="space-y-4" @submit.prevent="submit">
      <div>
        <label for="name" class="block text-sm font-medium text-[--color-text-secondary] mb-1.5">Full name</label>
        <input
          id="name"
          v-model="form.name"
          type="text"
          autocomplete="name"
          required
          :class="[
            'w-full px-3 py-2 text-sm rounded-lg border bg-[--color-surface-elevated] text-[--color-text-primary] placeholder-[--color-text-placeholder] transition-colors duration-[--duration-fast]',
            form.errors.name
              ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
              : 'border-[--color-border] focus:outline-none focus:ring-1 focus:ring-[--color-border-focus] focus:border-[--color-border-focus]',
          ]"
          placeholder="Jane Smith"
        />
        <p v-if="form.errors.name" class="mt-1 text-xs text-rose-600">{{ form.errors.name }}</p>
      </div>

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
          autocomplete="new-password"
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

      <div>
        <label for="password_confirmation" class="block text-sm font-medium text-[--color-text-secondary] mb-1.5">Confirm password</label>
        <input
          id="password_confirmation"
          v-model="form.password_confirmation"
          type="password"
          autocomplete="new-password"
          required
          class="w-full px-3 py-2 text-sm rounded-lg border border-[--color-border] bg-[--color-surface-elevated] text-[--color-text-primary] focus:outline-none focus:ring-1 focus:ring-[--color-border-focus] focus:border-[--color-border-focus] transition-colors duration-[--duration-fast]"
          placeholder="••••••••"
        />
      </div>

      <button
        type="submit"
        :disabled="form.processing"
        class="w-full py-2.5 px-4 text-sm font-medium rounded-lg bg-[--color-accent-600] text-white hover:bg-[--color-accent-700] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[--duration-fast]"
      >
        {{ form.processing ? 'Creating account…' : 'Create account' }}
      </button>
    </form>

    <p class="mt-5 text-center text-sm text-[--color-text-muted]">
      Already have an account?
      <a href="/login" class="text-[--color-text-link] hover:underline">Sign in</a>
    </p>
  </AuthLayout>
</template>
