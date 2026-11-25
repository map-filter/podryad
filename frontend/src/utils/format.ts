import type { DocState, ItemState, ReadyStatus, RequestStatus } from '@/api'

export function formatDate(value: string | null | undefined): string {
  if (!value) return ''
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
  if (!m) return value
  return `${m[3]}.${m[2]}.${m[1]}`
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return ''
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleString('ru-RU', { timeZone: 'Asia/Almaty', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

export function plural(n: number, one: string, few: string, many: string): string {
  const a = Math.abs(n) % 100
  const b = a % 10
  if (a > 10 && a < 20) return many
  if (b > 1 && b < 5) return few
  if (b === 1) return one
  return many
}

export function days(n: number): string {
  return `${n} ${plural(n, 'день', 'дня', 'дней')}`
}

export function roman(n: number | null | undefined): string {
  if (!n) return ''
  return ['', 'I', 'II', 'III', 'IV', 'V'][n] ?? String(n)
}

export function fileSize(bytes: number | null | undefined): string {
  if (!bytes) return ''
  if (bytes < 1024) return `${bytes} Б`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} КБ`
  return `${(bytes / 1024 / 1024).toFixed(1)} МБ`
}

export function todayIso(offsetDays = 0): string {
  const d = new Date(Date.now() + offsetDays * 86400000)
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Almaty', year: 'numeric', month: '2-digit', day: '2-digit' }).format(d)
  return parts
}

export const readyLabels: Record<ReadyStatus, string> = {
  ready: 'Готов',
  expiring: 'Истекает срок',
  not_ready: 'Не готов',
}

export const readyTones: Record<ReadyStatus, string> = {
  ready: 'tone-success',
  expiring: 'tone-warning',
  not_ready: 'tone-danger',
}

export function readyText(status: ReadyStatus, threshold: number | null, daysLeft: number | null): string {
  if (status === 'expiring' && daysLeft !== null) return `Истекает через ${days(daysLeft)}`
  if (status === 'expiring' && threshold) return `Истекает (до ${threshold} дн.)`
  return readyLabels[status]
}

export function levelTone(status: ReadyStatus, threshold: number | null): string {
  if (status !== 'expiring') return readyTones[status]
  if (threshold !== null && threshold <= 7) return 'tone-orange'
  return 'tone-warning'
}

export const docStateLabels: Record<DocState, string> = {
  valid: 'Действует',
  unlimited: 'Бессрочно',
  expiring: 'Истекает',
  expired: 'Просрочен',
}

export function docTone(state: DocState, threshold: number | null): string {
  if (state === 'expired') return 'tone-danger'
  if (state === 'expiring') return threshold !== null && threshold <= 7 ? 'tone-orange' : 'tone-warning'
  return 'tone-success'
}

export function docText(state: DocState, daysLeft: number | null): string {
  if (state === 'expired' && daysLeft !== null) return `Просрочен ${days(-daysLeft)} назад`
  if (state === 'expiring' && daysLeft !== null) return daysLeft === 0 ? 'Истекает сегодня' : `Через ${days(daysLeft)}`
  return docStateLabels[state]
}

export const itemTones: Record<ItemState, string> = {
  ok: 'tone-success',
  expiring: 'tone-warning',
  expires_during: 'tone-warning',
  expired: 'tone-danger',
  missing: 'tone-danger',
  low_group: 'tone-danger',
}

export const requestStatusLabels: Record<RequestStatus, string> = {
  draft: 'Черновик',
  submitted: 'Подана заказчику',
  approved: 'Допущены',
  remarks: 'Замечания',
}

export const requestStatusTones: Record<RequestStatus, string> = {
  draft: 'tone-muted',
  submitted: 'tone-info',
  approved: 'tone-success',
  remarks: 'tone-danger',
}

export const categoryLabels: Record<string, string> = {
  med: 'Медицина',
  briefing: 'Инструктажи',
  ot: 'Охрана труда и ПБ',
  special: 'Особые виды работ',
  profession: 'Профессия',
  ppe: 'СИЗ',
}
