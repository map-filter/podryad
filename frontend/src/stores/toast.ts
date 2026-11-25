import { reactive } from 'vue'
import { errorMessage } from '@/utils/errors'

export type ToastKind = 'success' | 'error' | 'info'

export interface Toast {
  id: number
  kind: ToastKind
  text: string
}

export const toasts = reactive<Toast[]>([])
let seq = 0

export function dismiss(id: number): void {
  const index = toasts.findIndex((t) => t.id === id)
  if (index >= 0) toasts.splice(index, 1)
}

export function notify(text: string, kind: ToastKind = 'success', timeout = 4500): void {
  const id = ++seq
  toasts.push({ id, kind, text })
  if (toasts.length > 4) toasts.shift()
  setTimeout(() => dismiss(id), kind === 'error' ? Math.max(timeout, 8000) : timeout)
}

export function notifyError(e: unknown, fallback?: string): void {
  notify(errorMessage(e, fallback), 'error')
}
