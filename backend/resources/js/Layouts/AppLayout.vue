<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { usePage, Link, router } from '@inertiajs/vue3'
import CompanySwitcher from '@/Components/App/CompanySwitcher.vue'
import ToastStack from '@/Components/UI/ToastStack.vue'
import ProductTourOverlay from '@/Components/Tour/ProductTourOverlay.vue'
import { useToasts } from '@/composables/useToasts'
import { useProductTour } from '@/composables/useProductTour'
import type { SharedProps } from '@/types'

const page = usePage<SharedProps>()
const company = computed(() => page.props.company)
const companies = computed(() => page.props.companies ?? [])
const user = computed(() => page.props.auth.user)

// Flash messages surface as dismissible toasts. Watching the shared prop
// (rather than rendering it inline) keeps working across Inertia visits
// under the persistent layout.
const { addToast } = useToasts()
watch(
  () => page.props.flash,
  (flash) => {
    if (flash?.success) addToast('success', flash.success)
    if (flash?.error) addToast('error', flash.error)
  },
  { immediate: true, deep: true },
)

const sidebarOpen = ref(false)

const navLinks = [
  { name: 'Dashboard', href: '/app', icon: 'home' },
  { name: 'Recommendations', href: '/app/recommendations', icon: 'sparkles' },
  { name: 'Opportunities', href: '/app/opportunities', icon: 'lightbulb' },
  { name: 'Business Brain', href: '/app/brain', icon: 'cpu' },
  { name: 'Campaigns', href: '/app/campaigns', icon: 'megaphone' },
  { name: 'Publishing', href: '/app/publishing', icon: 'paper-airplane' },
  { name: 'Analytics', href: '/app/analytics', icon: 'chart-bar' },
  { name: 'Learning', href: '/app/learning', icon: 'academic-cap' },
]

// page.props/url are reactive across Inertia visits — window.location is
// not, and would leave stale active states under the persistent layout.
const currentPath = computed(() => page.url.split('?')[0])

function isActive(href: string): boolean {
  if (href === '/app') {
    return currentPath.value === '/app'
  }
  return currentPath.value.startsWith(href)
}

// The tour only has anchors on the Dashboard, so it's only started there —
// either automatically for a first-time user, or on request from Settings'
// "Take the product tour" relaunch link (the `pendingStart` flag avoids
// depending on a post-navigation callback race).
const { state: tourState, startTour } = useProductTour()
watch(
  () => [currentPath.value, user.value?.has_completed_tour, tourState.pendingStart],
  () => {
    if (currentPath.value !== '/app') return
    if (tourState.pendingStart || user.value?.has_completed_tour === false) startTour()
  },
  { immediate: true },
)

function logout(): void {
  router.post('/logout')
}
</script>

<template>
  <div class="min-h-screen bg-[var(--color-surface)]">
    <!-- Mobile top bar -->
    <div class="lg:hidden flex items-center justify-between px-4 h-14 bg-[var(--color-surface-elevated)] border-b border-[var(--color-border)]">
      <span class="font-semibold text-[var(--color-text-primary)]">Atlas</span>
      <button
        class="p-2 rounded-lg text-[var(--color-text-muted)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
        aria-label="Open navigation"
        @click="sidebarOpen = !sidebarOpen"
      >
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
          <path v-if="!sidebarOpen" stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
          <path v-else stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Sidebar overlay (mobile) -->
    <div
      v-if="sidebarOpen"
      class="fixed inset-0 z-40 lg:hidden"
      @click="sidebarOpen = false"
    >
      <div class="absolute inset-0 bg-[var(--color-surface-overlay)] opacity-50" />
    </div>

    <!-- Sidebar -->
    <aside
      :class="[
        'fixed top-0 left-0 z-50 h-full w-60 bg-[var(--color-surface-elevated)] border-r border-[var(--color-border)] flex flex-col transition-transform duration-[var(--duration-smooth)]',
        'lg:translate-x-0',
        sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
      ]"
    >
      <!-- Logo -->
      <div class="flex items-center gap-2 px-4 h-16 border-b border-[var(--color-border)] shrink-0">
        <span class="font-semibold text-lg text-[var(--color-text-primary)] tracking-tight">Atlas</span>
        <div v-if="company" class="ml-auto min-w-0">
          <CompanySwitcher
            v-if="companies.length > 1"
            :company="company"
            :companies="companies"
          />
          <span
            v-else
            class="text-xs font-medium text-[var(--color-text-muted)] truncate max-w-24 block"
          >{{ company.name }}</span>
        </div>
      </div>

      <!-- Navigation -->
      <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-0.5" aria-label="Main navigation">
        <Link
          v-for="link in navLinks"
          :key="link.href"
          :href="link.href"
          :class="[
            'flex items-center gap-3 px-3 h-10 rounded-lg text-sm transition-colors duration-[var(--duration-fast)]',
            isActive(link.href)
              ? 'bg-[var(--color-accent-50)] text-[var(--color-accent-600)] font-medium'
              : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] hover:text-[var(--color-text-primary)]',
          ]"
          @click="sidebarOpen = false"
        >
          <span class="size-5 shrink-0" aria-hidden="true">
            <!-- Icon placeholder — replaced with Heroicons in component builds -->
            <svg v-if="link.icon === 'home'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
            <svg v-else-if="link.icon === 'sparkles'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" /></svg>
            <svg v-else-if="link.icon === 'lightbulb'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
            <svg v-else-if="link.icon === 'cpu'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 0 0 2.25-2.25V6.75a2.25 2.25 0 0 0-2.25-2.25H6.75A2.25 2.25 0 0 0 4.5 6.75v10.5a2.25 2.25 0 0 0 2.25 2.25Zm.75-12h9v9h-9v-9Z" /></svg>
            <svg v-else-if="link.icon === 'megaphone'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 1 8.835-2.535m0 0A23.74 23.74 0 0 1 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46" /></svg>
            <svg v-else-if="link.icon === 'paper-airplane'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
            <svg v-else-if="link.icon === 'chart-bar'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
            <svg v-else-if="link.icon === 'academic-cap'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 3.741-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5" /></svg>
            <svg v-else fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><circle cx="12" cy="12" r="3" /></svg>
          </span>
          {{ link.name }}
        </Link>
      </nav>

      <!-- Settings + user -->
      <div class="border-t border-[var(--color-border)] p-3 space-y-0.5 shrink-0">
        <Link
          href="/app/settings"
          class="flex items-center gap-3 px-3 h-10 rounded-lg text-sm text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] hover:text-[var(--color-text-primary)] transition-colors duration-[var(--duration-fast)]"
          @click="sidebarOpen = false"
        >
          <svg class="size-5 shrink-0" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
          Settings
        </Link>

        <!-- User menu -->
        <div class="flex items-center gap-2 px-3 py-2">
          <div class="size-7 rounded-full bg-[var(--color-accent-100)] flex items-center justify-center shrink-0">
            <span class="text-xs font-medium text-[var(--color-accent-600)]">{{ user?.name?.[0]?.toUpperCase() }}</span>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-xs font-medium text-[var(--color-text-primary)] truncate">{{ user?.name }}</p>
            <p class="text-xs text-[var(--color-text-muted)] truncate">{{ user?.email }}</p>
          </div>
          <button
            class="text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)] transition-colors duration-[var(--duration-fast)]"
            aria-label="Sign out"
            @click="logout"
          >
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" /></svg>
          </button>
        </div>
      </div>
    </aside>

    <!-- Main content -->
    <div class="lg:pl-60 min-h-screen flex flex-col">
      <main class="flex-1 px-4 py-6 lg:px-8">
        <slot />
      </main>
    </div>

    <!-- Dismissible toasts (flash + programmatic) -->
    <ToastStack />

    <!-- First-time-user product tour (Dashboard-only) -->
    <ProductTourOverlay />
  </div>
</template>
