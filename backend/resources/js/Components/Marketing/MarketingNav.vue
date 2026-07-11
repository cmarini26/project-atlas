<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useWindowScroll } from '@vueuse/core'
import { Bars3Icon, XMarkIcon } from '@heroicons/vue/24/outline'
import MarketingButton from './MarketingButton.vue'

const { y } = useWindowScroll()
const isMenuOpen = ref(false)
const firstMenuLink = ref<HTMLAnchorElement | null>(null)

const links = [
  { label: 'How It Works', href: '#how-it-works' },
  { label: 'Industries', href: '#industries' },
]

function closeMenu(): void {
  isMenuOpen.value = false
}

watch(isMenuOpen, async (open) => {
  if (open) {
    await nextTick()
    firstMenuLink.value?.focus()
  }
})
</script>

<template>
  <a
    href="#main-content"
    class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[100] focus:rounded-lg focus:bg-[var(--color-accent-600)] focus:px-4 focus:py-2 focus:text-white focus:text-body"
  >
    Skip to content
  </a>

  <header
    :class="[
      'fixed top-0 inset-x-0 z-50 transition-colors duration-200 ease-[var(--ease-standard)]',
      y > 60 ? 'bg-white/80 backdrop-blur-sm border-b border-[var(--color-border)]' : 'bg-transparent',
    ]"
  >
    <nav aria-label="Main navigation" class="mx-auto max-w-[1280px] px-4 sm:px-8 h-16 flex items-center justify-between">
      <Link href="/" class="text-heading-2 font-semibold text-[var(--color-text-primary)] tracking-tight">
        Atlas
      </Link>

      <ul class="hidden lg:flex items-center gap-8">
        <li v-for="link in links" :key="link.href">
          <a
            :href="link.href"
            class="text-body text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] transition-colors duration-100"
          >
            {{ link.label }}
          </a>
        </li>
      </ul>

      <div class="hidden lg:flex items-center gap-4">
        <Link href="/login" class="text-body text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)] transition-colors duration-100">
          Sign In
        </Link>
        <MarketingButton href="/register" variant="primary" size="md">Start Free</MarketingButton>
      </div>

      <button
        type="button"
        class="lg:hidden inline-flex items-center justify-center size-10 rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)]"
        aria-haspopup="true"
        :aria-expanded="isMenuOpen"
        aria-controls="mobile-menu"
        @click="isMenuOpen = true"
      >
        <Bars3Icon class="size-6" aria-hidden="true" />
        <span class="sr-only">Open menu</span>
      </button>
    </nav>
  </header>

  <div
    v-if="isMenuOpen"
    id="mobile-menu"
    class="fixed inset-0 z-[60] bg-white flex flex-col lg:hidden"
    role="dialog"
    aria-modal="true"
    aria-label="Main navigation"
  >
    <div class="flex items-center justify-between h-16 px-4 sm:px-8">
      <span class="text-heading-2 font-semibold text-[var(--color-text-primary)]">Atlas</span>
      <button
        type="button"
        class="inline-flex items-center justify-center size-10 rounded-lg text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)]"
        @click="closeMenu"
      >
        <XMarkIcon class="size-6" aria-hidden="true" />
        <span class="sr-only">Close menu</span>
      </button>
    </div>

    <ul class="flex-1 flex flex-col items-start gap-1 px-6 pt-4">
      <li v-for="(link, index) in links" :key="link.href" class="w-full">
        <a
          :ref="index === 0 ? (el) => (firstMenuLink = el as HTMLAnchorElement) : undefined"
          :href="link.href"
          class="block w-full py-3 text-heading-2 text-[var(--color-text-primary)]"
          @click="closeMenu"
        >
          {{ link.label }}
        </a>
      </li>
    </ul>

    <div class="px-6 pb-8 pt-4 flex flex-col gap-3 border-t border-[var(--color-border)]">
      <Link href="/login" class="text-center py-2 text-body text-[var(--color-text-secondary)]" @click="closeMenu">
        Sign In
      </Link>
      <MarketingButton href="/register" variant="primary" size="lg" class="w-full" @click="closeMenu">
        Start Free
      </MarketingButton>
    </div>
  </div>
</template>
