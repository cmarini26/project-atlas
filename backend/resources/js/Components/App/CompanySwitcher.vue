<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { onClickOutside } from '@vueuse/core'
import type { Company, CompanyOption } from '@/types'

const props = defineProps<{
  company: Company
  companies: CompanyOption[]
}>()

const open = ref(false)
const switching = ref(false)
const root = ref<HTMLElement | null>(null)

onClickOutside(root, () => {
  open.value = false
})

const others = computed(() => props.companies.filter((c) => c.id !== props.company.id))

function switchTo(companyId: string): void {
  if (switching.value || companyId === props.company.id) return

  switching.value = true

  // The server validates membership and stores the selection in the
  // session, then redirects to the dashboard for the new company.
  router.post('/company/select', { company_id: companyId }, {
    onFinish: () => {
      switching.value = false
      open.value = false
    },
  })
}
</script>

<template>
  <div ref="root" class="relative min-w-0">
    <button
      type="button"
      class="flex items-center gap-1 max-w-32 text-xs font-medium text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)] transition-colors duration-[var(--duration-fast)]"
      :aria-expanded="open"
      aria-haspopup="menu"
      aria-label="Switch company"
      @click="open = !open"
    >
      <span class="truncate">{{ company.name }}</span>
      <svg class="size-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
    </button>

    <div
      v-if="open"
      class="absolute right-0 top-full mt-1.5 w-56 z-50 bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-lg shadow-lg py-1"
      role="menu"
    >
      <p class="px-3 py-1.5 text-[10px] font-semibold text-[var(--color-text-muted)] uppercase tracking-widest">Switch company</p>

      <button
        v-for="option in others"
        :key="option.id"
        type="button"
        role="menuitem"
        :disabled="switching"
        class="w-full text-left px-3 py-2 text-sm text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] disabled:opacity-60 truncate transition-colors duration-[var(--duration-fast)]"
        @click="switchTo(option.id)"
      >
        {{ option.name }}
      </button>
    </div>
  </div>
</template>
