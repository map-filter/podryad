import { ApiError } from '@/api'
import { SignError } from '@/services/signer'

export function errorMessage(e: unknown, fallback = 'Что-то пошло не так. Попробуйте ещё раз'): string {
  if (e instanceof ApiError || e instanceof SignError) return e.message
  if (e instanceof Error && e.message) return e.message
  return fallback
}

export function fieldErrors(e: unknown): Record<string, string> {
  const out: Record<string, string> = {}
  if (e instanceof ApiError && e.status === 422) {
    for (const [key, list] of Object.entries(e.errors)) {
      if (list?.length) out[key] = list[0]
    }
  }
  return out
}
