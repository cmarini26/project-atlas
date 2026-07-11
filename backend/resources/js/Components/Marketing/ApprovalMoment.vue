<script setup lang="ts">
import { ShieldCheckIcon } from '@heroicons/vue/24/outline'
import { useScrollReveal } from '@/composables/useScrollReveal'

const { target, isVisible } = useScrollReveal()

const points = [
  'Every recommendation requires one approval action before anything is published',
  'You can edit any piece of content before approving — your version is what publishes',
  'Every approval, edit, and pass is permanently recorded — Atlas never forgets what you wanted',
  'Nothing is ever scheduled automatically — Atlas queues, you release',
]
</script>

<template>
  <section class="py-20 sm:py-28 px-4 sm:px-8 bg-[var(--color-surface-overlay)]">
    <div
      ref="target"
      class="mx-auto max-w-[720px] text-center transition-opacity duration-300 ease-[var(--ease-out)]"
      :class="isVisible ? 'opacity-100' : 'opacity-0'"
    >
      <h2 class="text-heading-1 sm:text-display text-[var(--color-text-inverse)] mb-8 text-balance">
        Atlas does not publish anything without you.
      </h2>

      <div class="space-y-5 text-body-lg text-stone-300 text-left">
        <p class="text-stone-100">This is not a disclaimer. It is the design.</p>

        <p>
          Atlas has access to your brand, your audience, and your publishing channels. A single
          post made at the wrong moment — with the wrong framing, the wrong channel, the wrong
          item — costs more than the campaign was worth.
        </p>

        <p>
          Before Atlas earns the right to act more independently, it has to prove that it
          understands your business well enough to be trusted. It proves that through
          recommendations you approve. Through edits you make. Through passes you give with a
          note explaining why.
        </p>

        <p>
          Every one of those signals makes Atlas more accurate. The approval step isn&rsquo;t the
          product getting out of the way of Atlas — it&rsquo;s Atlas getting smarter with your
          help.
        </p>
      </div>

      <p class="mt-10 text-heading-2 text-[var(--color-text-inverse)]">
        Zero campaigns published without explicit approval. It&rsquo;s structural, not a policy.
      </p>

      <ul class="mt-10 space-y-4 text-left">
        <li v-for="point in points" :key="point" class="flex gap-3 text-body text-stone-300">
          <ShieldCheckIcon class="size-5 shrink-0 text-[var(--color-accent-200)]" aria-hidden="true" />
          <span>{{ point }}</span>
        </li>
      </ul>
    </div>
  </section>
</template>
