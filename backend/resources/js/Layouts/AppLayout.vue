<script setup lang="ts">
import { ref, computed, watch, type Component } from 'vue'
import { usePage, Link, router } from '@inertiajs/vue3'
import {
  HomeIcon,
  CpuChipIcon,
  HeartIcon,
  LightBulbIcon,
  SparklesIcon,
  MegaphoneIcon,
  PaperAirplaneIcon,
  ChartBarIcon,
  AcademicCapIcon,
  Cog6ToothIcon,
  Bars3Icon,
  XMarkIcon,
  ArrowRightOnRectangleIcon,
} from '@heroicons/vue/24/outline'
import CompanySwitcher from '@/Components/App/CompanySwitcher.vue'
import ToastStack from '@/Components/UI/ToastStack.vue'
import ProductTourOverlay from '@/Components/Tour/ProductTourOverlay.vue'
import FeedbackPrompt from '@/Components/App/FeedbackPrompt.vue'
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

// Lets assistive tech announce that new page content is loading, since
// Inertia navigations don't trigger a full page load a screen reader
// would otherwise treat as a state change.
const isNavigating = ref(false)
router.on('start', () => { isNavigating.value = true })
router.on('finish', () => { isNavigating.value = false })

// Keep the main customer workflow small and obvious. Atlas still has deeper
// insight pages, but production customers should not have to scan the full
// internal loop every time they open the app.
const navGroups: { group: string | null; links: { name: string; href: string; icon: Component }[] }[] = [
  {
    group: null,
    links: [
      { name: 'Overview', href: '/app', icon: HomeIcon },
      { name: 'Recommendations', href: '/app/recommendations', icon: SparklesIcon },
      { name: 'Campaigns', href: '/app/campaigns', icon: MegaphoneIcon },
      { name: 'Analytics', href: '/app/analytics', icon: ChartBarIcon },
    ],
  },
  {
    group: 'Deeper insight',
    links: [
      { name: 'Opportunities', href: '/app/opportunities', icon: LightBulbIcon },
      { name: 'Business Brain', href: '/app/brain', icon: CpuChipIcon },
      { name: 'Marketing Health', href: '/app/marketing-health', icon: HeartIcon },
      { name: 'Learning', href: '/app/learning', icon: AcademicCapIcon },
    ],
  },
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
    <div class="lg:hidden flex items-center justify-between px-4 h-14 bg-[var(--color-surface-nav)] border-b border-[var(--color-border)] shadow-[var(--shadow-card)]">
      <div class="flex items-center gap-2">
        <span class="size-7 rounded-[var(--radius-sm)] bg-[var(--color-accent-50)] text-[var(--color-accent-700)] grid place-items-center text-sm font-bold border border-[var(--color-accent-200)]">A</span>
        <span class="font-semibold text-[var(--color-text-primary)]">Atlas</span>
      </div>
      <button
        class="p-2 rounded-[var(--radius-sm)] text-[var(--color-text-muted)] hover:bg-[var(--color-surface-elevated)] hover:text-[var(--color-text-primary)] transition-colors duration-[var(--duration-fast)]"
        aria-label="Open navigation"
        @click="sidebarOpen = !sidebarOpen"
      >
        <Bars3Icon v-if="!sidebarOpen" class="size-5" aria-hidden="true" />
        <XMarkIcon v-else class="size-5" aria-hidden="true" />
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
        'fixed top-0 left-0 z-50 h-full w-64 bg-[var(--color-surface-nav)] text-[var(--color-text-primary)] flex flex-col transition-transform duration-[var(--duration-smooth)] shadow-[var(--shadow-modal)] border-r border-[var(--color-border)]',
        'lg:translate-x-0',
        sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
      ]"
    >
      <!-- Logo -->
      <div class="border-b border-[var(--color-border)] shrink-0 px-4 py-4">
        <div class="flex items-center gap-3">
          <span class="size-9 rounded-[var(--radius-sm)] bg-[var(--color-accent-50)] text-[var(--color-accent-700)] grid place-items-center text-base font-bold border border-[var(--color-accent-200)] shadow-[var(--shadow-xs)]">A</span>
          <div class="min-w-0">
            <span class="block font-semibold text-lg text-[var(--color-text-primary)] tracking-tight leading-none">Atlas</span>
            <span class="block text-[0.6875rem] font-medium uppercase tracking-[0.18em] text-[var(--color-text-muted)] whitespace-nowrap">Marketing OS</span>
          </div>
        </div>
        <div v-if="company" class="mt-4 min-w-0">
          <CompanySwitcher
            v-if="companies.length > 1"
            :company="company"
            :companies="companies"
          />
          <span
            v-else
            class="block truncate rounded-[var(--radius-sm)] bg-[var(--color-surface-elevated)] px-3 py-2 text-xs font-medium text-[var(--color-text-secondary)] border border-[var(--color-border)]"
          >{{ company.name }}</span>
        </div>
      </div>

      <!-- Navigation -->
      <nav class="flex-1 overflow-y-auto py-5 px-3" aria-label="Main navigation">
        <div v-for="group in navGroups" :key="group.group ?? 'top'" class="mb-5 last:mb-0">
          <p
            v-if="group.group"
            class="px-3 mb-2 text-[0.6875rem] font-semibold text-[var(--color-text-muted)] uppercase tracking-[0.18em]"
          >
            {{ group.group }}
          </p>
          <div class="space-y-1">
            <Link
              v-for="link in group.links"
              :key="link.href"
              :href="link.href"
              :class="[
                'relative flex items-center gap-3 px-3 h-10 rounded-[var(--radius-sm)] text-sm transition-colors duration-[var(--duration-fast)]',
                isActive(link.href)
                  ? 'bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] font-semibold shadow-[var(--shadow-card)] ring-1 ring-[var(--color-border)]'
                  : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-elevated)] hover:text-[var(--color-text-primary)]',
              ]"
              @click="sidebarOpen = false"
            >
              <span
                v-if="isActive(link.href)"
                class="absolute left-0 top-2 bottom-2 w-1 rounded-r-full bg-[var(--color-accent-500)]"
                aria-hidden="true"
              />
              <component :is="link.icon" class="size-5 shrink-0" aria-hidden="true" />
              {{ link.name }}
            </Link>
          </div>
        </div>
      </nav>

      <!-- Settings + user -->
      <div class="border-t border-[var(--color-border)] p-3 space-y-1 shrink-0">
        <Link
          href="/app/publishing"
          :class="[
            'relative flex items-center gap-3 px-3 h-10 rounded-[var(--radius-sm)] text-sm transition-colors duration-[var(--duration-fast)]',
            isActive('/app/publishing')
              ? 'bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] font-semibold shadow-[var(--shadow-card)] ring-1 ring-[var(--color-border)]'
              : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-elevated)] hover:text-[var(--color-text-primary)]',
          ]"
          @click="sidebarOpen = false"
        >
          <span
            v-if="isActive('/app/publishing')"
            class="absolute left-0 top-2 bottom-2 w-1 rounded-r-full bg-[var(--color-accent-500)]"
            aria-hidden="true"
          />
          <PaperAirplaneIcon class="size-5 shrink-0" aria-hidden="true" />
          Publishing Queue
        </Link>

        <Link
          href="/app/settings"
          :class="[
            'relative flex items-center gap-3 px-3 h-10 rounded-[var(--radius-sm)] text-sm transition-colors duration-[var(--duration-fast)]',
            isActive('/app/settings')
              ? 'bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] font-semibold shadow-[var(--shadow-card)] ring-1 ring-[var(--color-border)]'
              : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-elevated)] hover:text-[var(--color-text-primary)]',
          ]"
          @click="sidebarOpen = false"
        >
          <span
            v-if="isActive('/app/settings')"
            class="absolute left-0 top-2 bottom-2 w-1 rounded-r-full bg-[var(--color-accent-500)]"
            aria-hidden="true"
          />
          <Cog6ToothIcon class="size-5 shrink-0" aria-hidden="true" />
          Settings
        </Link>

        <!-- User menu -->
        <div class="flex items-center gap-2 px-3 py-3 rounded-[var(--radius-md)] bg-[var(--color-surface-elevated)] border border-[var(--color-border)]">
          <div class="size-8 rounded-full bg-[var(--color-accent-50)] text-[var(--color-accent-700)] flex items-center justify-center shrink-0 border border-[var(--color-accent-200)]">
            <span class="text-xs font-semibold">{{ user?.name?.[0]?.toUpperCase() }}</span>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-xs font-semibold text-[var(--color-text-primary)] truncate">{{ user?.name }}</p>
            <p class="text-xs text-[var(--color-text-muted)] truncate">{{ user?.email }}</p>
          </div>
          <button
            class="text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)] transition-colors duration-[var(--duration-fast)]"
            aria-label="Sign out"
            @click="logout"
          >
            <ArrowRightOnRectangleIcon class="size-4" aria-hidden="true" />
          </button>
        </div>
      </div>
    </aside>

    <!-- Main content -->
    <div class="lg:pl-64 min-h-screen flex flex-col">
      <main class="flex-1 px-4 py-5 sm:px-6 lg:px-10 lg:py-8" :aria-busy="isNavigating">
        <div class="mx-auto w-full max-w-7xl">
          <slot />
        </div>
      </main>
    </div>

    <!-- Dismissible toasts (flash + programmatic) -->
    <ToastStack />

    <!-- First-time-user product tour (Dashboard-only) -->
    <ProductTourOverlay />

    <!-- NPS-style feedback prompt (shown 24h after a first approval) -->
    <FeedbackPrompt />
  </div>
</template>
