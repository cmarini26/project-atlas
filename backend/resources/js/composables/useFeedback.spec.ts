import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useFeedback } from './useFeedback'

const postMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
  router: {
    post: (url: string, data: unknown, options: { onFinish?: () => void }) => {
      postMock(url, data, options)
      options.onFinish?.()
    },
  },
}))

describe('useFeedback', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  afterEach(() => {
    postMock.mockClear()
    useFeedback().close()
    sessionStorage.clear()
  })

  it('opens the prompt', () => {
    const { state, open } = useFeedback()

    open()

    expect(state.isOpen).toBe(true)
  })

  it('does not reopen once closed this session', () => {
    const { state, open, close } = useFeedback()

    open()
    close()
    expect(state.isOpen).toBe(false)

    open()
    expect(state.isOpen).toBe(false)
  })

  it('submit posts the score and comment, then closes', () => {
    const { state, open, submit } = useFeedback()
    open()

    submit(9, 'Great so far')

    expect(postMock).toHaveBeenCalledWith(
      '/app/feedback',
      { score: 9, comment: 'Great so far' },
      expect.any(Object),
    )
  })

  it('submit sends null for an empty comment', () => {
    const { submit } = useFeedback()

    submit(5, '')

    expect(postMock).toHaveBeenCalledWith('/app/feedback', { score: 5, comment: null }, expect.any(Object))
  })
})
