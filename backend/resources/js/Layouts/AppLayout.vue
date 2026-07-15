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

// Grouped to mirror Atlas's own Observe → Understand → Decide → Recommend →
// Prepare → Approve → Execute → Measure → Learn loop, rather than one flat
// 8-item list with no hierarchy. `group: null` renders without a heading.
const navGroups: { group: string | null; links: { name: string; href: string; icon: Component }[] }[] = [
  {
    group: null,
    links: [{ name: 'Dashboard', href: '/app', icon: HomeIcon }],
  },
  {
    group: 'Understand',
    links: [
      { name: 'Business Brain', href: '/app/brain', icon: CpuChipIcon },
      { name: 'Marketing Health', href: '/app/marketing-health', icon: HeartIcon },
      { name: 'Opportunities', href: '/app/opportunities', icon: LightBulbIcon },
    ],
  },
  {
    group: 'Act',
    links: [
      { name: 'Recommendations', href: '/app/recommendations', icon: SparklesIcon },
      { name: 'Campaigns', href: '/app/campaigns', icon: MegaphoneIcon },
      { name: 'Publishing Queue', href: '/app/publishing', icon: PaperAirplaneIcon },
    ],
  },
  {
    group: 'Measure',
    links: [
      { name: 'Analytics', href: '/app/analytics', icon: ChartBarIcon },
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
    <div class="lg:hidden flex items-center justify-between px-4 h-14 bg-[var(--color-surface-elevated)] border-b border-[var(--color-border)]">
      <span class="font-semibold text-[var(--color-text-primary)]">Atlas</span>
      <button
        class="p-2 rounded-lg text-[var(--color-text-muted)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
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
      <nav class="flex-1 overflow-y-auto py-4 px-3" aria-label="Main navigation">
        <div v-for="group in navGroups" :key="group.group ?? 'top'" class="mb-4 last:mb-0">
          <p
            v-if="group.group"
            class="px-3 mb-1 text-[0.6875rem] font-medium text-[var(--color-text-placeholder)] uppercase tracking-widest"
          >
            {{ group.group }}
          </p>
          <div class="space-y-0.5">
            <Link
              v-for="link in group.links"
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
              <component :is="link.icon" class="size-5 shrink-0" aria-hidden="true" />
              {{ link.name }}
            </Link>
          </div>
        </div>
      </nav>

      <!-- Settings + user -->
      <div class="border-t border-[var(--color-border)] p-3 space-y-0.5 shrink-0">
        <Link
          href="/app/settings"
          :class="[
            'flex items-center gap-3 px-3 h-10 rounded-lg text-sm transition-colors duration-[var(--duration-fast)]',
            isActive('/app/settings')
              ? 'bg-[var(--color-accent-50)] text-[var(--color-accent-600)] font-medium'
              : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] hover:text-[var(--color-text-primary)]',
          ]"
          @click="sidebarOpen = false"
        >
          <Cog6ToothIcon class="size-5 shrink-0" aria-hidden="true" />
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
            <ArrowRightOnRectangleIcon class="size-4" aria-hidden="true" />
          </button>
        </div>
      </div>
    </aside>

    <!-- Main content -->
    <div class="lg:pl-60 min-h-screen flex flex-col">
      <main class="flex-1 px-4 py-6 lg:px-8" :aria-busy="isNavigating">
        <slot />
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
