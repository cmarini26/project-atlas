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

  it('shows a confirmation dialog naming the content, channel, and its real capability', async () => {
    const wrapper = mount(ApproveActions, {
      props: { recommendationId: 'rec-1', contentAssets: assets },
      attachTo: document.body,
    })

    await wrapper.find('button').trigger('click')

    const dialogText = document.body.textContent ?? ''
    expect(dialogText).toContain('Approve this recommendation?')
    expect(dialogText).toContain('blog post')
    expect(dialogText).toContain('Rare finds this week')
    expect(dialogText).toContain('Blog')
    // Must never imply the content goes live — no channel truly publishes yet.
    expect(dialogText).toContain('Draft only')
    expect(dialogText).toContain('delivery is simulated for every company on this channel type today')

    wrapper.unmount()
  })

  it('tells the user manual action would enable real sending for a supported-but-unconnected channel', async () => {
    const unconnectedAssets: ContentAsset[] = [
      {
        id: 'asset-3',
        type: 'social_post',
        body: 'Body text',
        title: 'New arrivals',
        status: 'draft',
        metadata: {},
        channel: { type: 'instagram', marketing_channel: null },
      },
    ]

    const wrapper = mount(ApproveActions, {
      props: { recommendationId: 'rec-1', contentAssets: unconnectedAssets },
      attachTo: document.body,
    })

    await wrapper.find('button').trigger('click')

    const dialogText = document.body.textContent ?? ''
    expect(dialogText).toContain('Manual action required')
    expect(dialogText).toContain('connect this channel in Settings to send it for real')

    wrapper.unmount()
  })

  it('tells the user a coming-later channel cannot send for anyone yet, not just this company', async () => {
    const unsupportedAssets: ContentAsset[] = [
      {
        id: 'asset-4',
        type: 'sms',
        body: 'Body text',
        title: null,
        status: 'draft',
        metadata: {},
        channel: { type: 'sms' },
      },
    ]

    const wrapper = mount(ApproveActions, {
      props: { recommendationId: 'rec-1', contentAssets: unsupportedAssets },
      attachTo: document.body,
    })

    await wrapper.find('button').trigger('click')

    const dialogText = document.body.textContent ?? ''
    expect(dialogText).toContain('Not configured')
    expect(dialogText).toContain("can't send for any company yet")

    wrapper.unmount()
  })

  it('says the send will really happen for a connected channel, never "not yet sent live"', async () => {
    const connectedAssets: ContentAsset[] = [
      {
        id: 'asset-2',
        type: 'email',
        body: 'Body text',
        title: 'Weekly newsletter',
        status: 'draft',
        metadata: {},
        channel: { type: 'email', marketing_channel: { supports_publishing: true } },
      },
    ]

    const wrapper = mount(ApproveActions, {
      props: { recommendationId: 'rec-1', contentAssets: connectedAssets },
      attachTo: document.body,
    })

    await wrapper.find('button').trigger('click')

    const dialogText = document.body.textContent ?? ''
    expect(dialogText).toContain('Connected')
    expect(dialogText).toContain('will really send to this connected channel')
    expect(dialogText).not.toContain('not yet sent live')

    wrapper.unmount()
  })

  it('falls back to a generic explanation when no content assets are provided', async () => {
    const wrapper = mount(ApproveActions, {
      props: { recommendationId: 'rec-1' },
      attachTo: document.body,
    })

    await wrapper.find('button').trigger('click')

    expect(document.body.textContent).toContain('No live channels are connected yet, so nothing is sent externally.')

    wrapper.unmount()
  })

  it('submits the approval only after confirming in the dialog', async () => {
    const wrapper = mount(ApproveActions, {
      props: { recommendationId: 'rec-42', contentAssets: assets },
      attachTo: document.body,
    })

    await wrapper.find('button').trigger('click')

    // Scope to the dialog: both the trigger and the confirm button read
    // "Approve" now that the misleading "Approve & publish" label is gone.
    const dialog = document.querySelector('[role="dialog"]')
    const confirmButton = Array.from(dialog?.querySelectorAll('button') ?? []).find(
      (b) => b.textContent?.trim() === 'Approve',
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
