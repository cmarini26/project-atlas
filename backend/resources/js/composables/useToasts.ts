import { reactive, readonly } from 'vue'

export interface Toast {
    id: number
    type: 'success' | 'error'
    message: string
}

const DEFAULT_DURATION_MS = 5000

let nextId = 1

const toasts = reactive<Toast[]>([])

const timers = new Map<number, ReturnType<typeof setTimeout>>()

function dismissToast(id: number): void {
    const index = toasts.findIndex((t) => t.id === id)
    if (index !== -1) toasts.splice(index, 1)

    const timer = timers.get(id)
    if (timer) {
        clearTimeout(timer)
        timers.delete(id)
    }
}

function addToast(type: Toast['type'], message: string, durationMs = DEFAULT_DURATION_MS): number {
    const id = nextId++
    toasts.push({ id, type, message })

    if (durationMs > 0) {
        timers.set(id, setTimeout(() => dismissToast(id), durationMs))
    }

    return id
}

function clearToasts(): void {
    toasts.splice(0, toasts.length)
    timers.forEach((timer) => clearTimeout(timer))
    timers.clear()
}

/**
 * Module-scoped toast store: one shared stack per browser tab. The stack
 * lives outside component state so it survives Inertia navigations under
 * the persistent AppLayout.
 */
export function useToasts() {
    return {
        toasts: readonly(toasts),
        addToast,
        dismissToast,
        clearToasts,
    }
}
