import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'
import FeedbackPrompt from './FeedbackPrompt.vue'
import { useFeedback } from '@/composables/useFeedback'

const postMock = vi.fn()
const pageProps = reactive({ show_feedback_prompt: false })

vi.mock('@inertiajs/vue3', () => ({
  router: {
    post: (url: string, data: unknown, options: { onFinish?: () => void }) => {
      postMock(url, data, options)
      options.onFinish?.()
    },
  },
  usePage: () => ({ props: pageProps }),
}))

let wrapper: VueWrapper | undefined

describe('FeedbackPrompt', () => {
  afterEach(() => {
    wrapper?.unmount()
    wrapper = undefined
    useFeedback().close()
    pageProps.show_feedback_prompt = false
    postMock.mockClear()
    sessionStorage.clear()
    document.body.innerHTML = ''
  })

  it('does not render when show_feedback_prompt is false', () => {
    wrapper = mount(FeedbackPrompt, { attachTo: document.body })

    expect(document.body.querySelector('[role="dialog"]')).toBeNull()
  })

  it('renders when show_feedback_prompt becomes true', async () => {
    wrapper = mount(FeedbackPrompt, { attachTo: document.body })

    pageProps.show_feedback_prompt = true
    await wrapper.vm.$nextTick()

    expect(document.body.querySelector('[role="dialog"]')).not.toBeNull()
    expect(document.body.textContent ?? '').toContain("How's Atlas working for you?")
  })

  it('disables submit until a score is picked', async () => {
    pageProps.show_feedback_prompt = true
    wrapper = mount(FeedbackPrompt, { attachTo: document.body })
    await wrapper.vm.$nextTick()

    const submitButton = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.includes('Send feedback'))
    expect(submitButton?.hasAttribute('disabled')).toBe(true)
  })

  it('submits the selected score', async () => {
    pageProps.show_feedback_prompt = true
    wrapper = mount(FeedbackPrompt, { attachTo: document.body })
    await wrapper.vm.$nextTick()

    const scoreButton = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === '9')
    scoreButton?.dispatchEvent(new Event('click'))
    await wrapper.vm.$nextTick()

    const submitButton = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.includes('Send feedback'))
    submitButton?.dispatchEvent(new Event('click'))

    expect(postMock).toHaveBeenCalledWith('/app/feedback', { score: 9, comment: null }, expect.any(Object))
  })

  it('closing suppresses the prompt for the session', async () => {
    pageProps.show_feedback_prompt = true
    wrapper = mount(FeedbackPrompt, { attachTo: document.body })
    await wrapper.vm.$nextTick()

    const closeButton = document.body.querySelector('button[aria-label="Dismiss"]')
    closeButton?.dispatchEvent(new Event('click'))
    await wrapper.vm.$nextTick()

    expect(document.body.querySelector('[role="dialog"]')).toBeNull()
  })
})
