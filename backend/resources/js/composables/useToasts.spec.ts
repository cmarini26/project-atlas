import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useToasts } from './useToasts'

describe('useToasts', () => {
  const { toasts, addToast, dismissToast, clearToasts } = useToasts()

  beforeEach(() => {
    vi.useFakeTimers()
    clearToasts()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('adds a toast with the given type and message', () => {
    addToast('success', 'Saved.')

    expect(toasts).toHaveLength(1)
    expect(toasts[0]).toMatchObject({ type: 'success', message: 'Saved.' })
  })

  it('dismisses a toast by id', () => {
    const id = addToast('error', 'Something broke.')

    dismissToast(id)

    expect(toasts).toHaveLength(0)
  })

  it('auto-dismisses after the default duration', () => {
    addToast('success', 'Auto-dismiss me')
    expect(toasts).toHaveLength(1)

    vi.advanceTimersByTime(5000)

    expect(toasts).toHaveLength(0)
  })

  it('does not auto-dismiss when durationMs is 0', () => {
    addToast('success', 'Sticky', 0)

    vi.advanceTimersByTime(60_000)

    expect(toasts).toHaveLength(1)
  })

  it('supports multiple simultaneous toasts', () => {
    addToast('success', 'First')
    addToast('error', 'Second')

    expect(toasts).toHaveLength(2)
  })

  it('clearToasts empties the stack and cancels pending timers', () => {
    addToast('success', 'One')
    addToast('success', 'Two')

    clearToasts()

    expect(toasts).toHaveLength(0)

    // No lingering timers should fire (and throw on an already-removed toast).
    vi.advanceTimersByTime(10_000)
    expect(toasts).toHaveLength(0)
  })
})
