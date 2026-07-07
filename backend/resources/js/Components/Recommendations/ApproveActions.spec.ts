import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import ApproveActions from './ApproveActions.vue'
import type { ContentAsset } from '@/types'

const postMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
  useForm: (initial: Record<string, unknown>) =>
    reactive({
      ...initial,
      processing: false,
      errors: {},
      post: postMock,
    }),
}))

const assets: ContentAsset[] = [
  {
    id: 'asset-1',
    type: 'blog_post',
    body: 'Body text',
    title: 'Rare finds this week',
    status: 'draft',
    metadata: {},
    channel: { type: 'blog' },
  },
]

describe('ApproveActions', () => {
  // ConfirmDialog teleports to the real document.body regardless of the
  // wrapper's mount target, so leftover dialogs from one test can pollute
  // querySelectorAll lookups in the next — always unmount.
  afterEach(() => {
    postMock.mockClear()
    document.body.innerHTML = ''
  })

  it('does not submit immediately when Approve is clicked', async () => {
    const wrapper = mount(ApproveActions, {
      props: { recommendationId: 'rec-1', contentAssets: assets },
      attachTo: document.body,
    })

    await wrapper.find('button').trigger('click')

    expect(postMock).not.toHaveBeenCalled()

    wrapper.unmount()
  })

  it('shows a confirmation dialog naming the content and destination channel', async () => {
    const wrapper = mount(ApproveActions, {
      props: { recommendationId: 'rec-1', contentAssets: assets },
      attachTo: document.body,
    })

    await wrapper.find('button').trigger('click')

    const dialogText = document.body.textContent ?? ''
    expect(dialogText).toContain('Approve this recommendation?')
    expect(dialogText).toContain('blog post')
    expect(dialogText).toContain('Rare finds this week')
    expect(dialogText).toContain('blog channel')

    wrapper.unmount()
  })

  it('falls back to a generic explanation when no content assets are provided', async () => {
    const wrapper = mount(ApproveActions, {
      props: { recommendationId: 'rec-1' },
      attachTo: document.body,
    })

    await wrapper.find('button').trigger('click')

    expect(document.body.textContent).toContain("Atlas will queue this campaign's content for publishing.")

    wrapper.unmount()
  })

  it('submits the approval only after confirming in the dialog', async () => {
    const wrapper = mount(ApproveActions, {
      props: { recommendationId: 'rec-42', contentAssets: assets },
      attachTo: document.body,
    })

    await wrapper.find('button').trigger('click')

    const confirmButton = Array.from(document.querySelectorAll('button')).find(
      (b) => b.textContent?.trim() === 'Approve & publish',
    )
    expect(confirmButton).toBeTruthy()

    confirmButton?.dispatchEvent(new Event('click', { bubbles: true }))
    await wrapper.vm.$nextTick()

    expect(postMock).toHaveBeenCalledWith(
      '/app/recommendations/rec-42/approve',
      expect.objectContaining({ preserveScroll: true }),
    )

    wrapper.unmount()
  })
})
