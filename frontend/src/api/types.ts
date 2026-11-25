export type SignMode = 'emulate' | 'ncalayer'
export type Role = 'head' | 'safety' | 'foreman' | 'customer'
export type ReadyStatus = 'ready' | 'expiring' | 'not_ready'
export type DocState = 'valid' | 'unlimited' | 'expiring' | 'expired'
export type ItemState = 'ok' | 'expiring' | 'expires_during' | 'expired' | 'missing' | 'low_group'
export type RequestStatus = 'draft' | 'submitted' | 'approved' | 'remarks'

export interface AppConfig {
  app_name: string
  sign_mode: SignMode
  mailpit_url: string | null
  telegram_configured: boolean
}

export interface User {
  id: number
  name: string
  email: string
  role: Role
  role_label: string
  position: string | null
  phone: string | null
  organization_id: number | null
  customer_id: number | null
  can_edit: boolean
  can_manage: boolean
  can_sign: boolean
}

export interface Organization {
  id: number
  name: string
  short_name: string | null
  bin: string
  city: string | null
  address: string | null
  director: string | null
  phone: string | null
  email: string | null
  notify_email: string | null
  notify_email_enabled: boolean
  telegram_chat_id: string | null
  notify_telegram_enabled: boolean
  notify_thresholds: number[]
}

export interface Facility {
  id: number
  customer_id: number
  name: string
  address: string | null
  notes: string | null
}

export interface Customer {
  id: number
  name: string
  short_name: string
  bin: string | null
  city: string | null
  industry: string | null
  contact: string | null
  facilities?: Facility[]
}

export interface Me {
  user: User
  organization: Organization | null
  customer: Customer | null
}

export interface ReadinessItem {
  document_type_id: number
  code: string
  name: string
  short_name: string
  min_group: number | null
  document_id: number | null
  number: string | null
  valid_until: string | null
  group_level: number | null
  days_left: number | null
  state: ItemState
  message: string
  has_scan?: boolean
  scan_mime?: string | null
}

export interface Readiness {
  status: ReadyStatus
  days_left: number | null
  threshold: number | null
  problems: string[]
  warnings: string[]
  items: ReadinessItem[]
}

export interface WorkerBrief {
  id: number
  last_name: string
  first_name: string
  middle_name: string | null
  full_name: string
  short_name: string
  iin: string
  birth_date: string | null
  position: string
  grade: number | null
  site: string | null
  phone: string | null
  hired_at: string | null
  is_active: boolean
  notes: string | null
  has_photo: boolean
  photo_version: number | null
}

export interface AttentionDoc {
  type_short: string
  state: DocState
  days_left: number | null
  valid_until: string | null
}

export interface WorkerRow extends WorkerBrief {
  documents_count: number
  readiness: Readiness
  attention: AttentionDoc[]
  nearest_days: number | null
}

export interface WorkerDocument {
  id: number
  worker_id: number
  document_type_id: number
  type_name: string | null
  type_short: string | null
  number: string | null
  issued_at: string | null
  valid_until: string | null
  issuer: string | null
  group_level: number | null
  notes: string | null
  has_scan: boolean
  scan_name: string | null
  scan_mime: string | null
  scan_size: number | null
  state: DocState
  days_left: number | null
  threshold: number | null
}

export interface WorkerCard extends WorkerBrief {
  readiness: Readiness
  readiness_tomorrow: Readiness
  documents: WorkerDocument[]
  requests: { id: number; number: string; status: RequestStatus; customer: string; start_date: string; end_date: string }[]
  gender: 'male' | 'female' | null
}

export interface DocumentType {
  id: number
  code: string
  name: string
  short_name: string
  category: string
  validity_months: number | null
  has_group: boolean
  is_basic: boolean
  description: string | null
  sort: number
  usage: number | null
}

export interface ProfileItem {
  document_type_id: number
  min_group: number | null
  name: string
  short_name: string
  has_group: boolean
}

export interface Counts {
  ready: number
  expiring: number
  not_ready: number
}

export interface Profile {
  id: number
  name: string
  work_type: string
  description: string | null
  customer_id: number | null
  customer: { id: number; short_name: string; name: string } | null
  items: ProfileItem[]
  counts?: Counts
  requests_count?: number
}

export interface RequestBrief {
  id: number
  number: string
  status: RequestStatus
  customer: { id: number; short_name: string; name: string } | null
  facility: { id: number; name: string } | null
  profile: { id: number; name: string } | null
  organization: { id: number; name: string; bin: string } | null
  work_type: string
  work_description: string | null
  start_date: string
  end_date: string
  workers_count: number
  signed_at: string | null
  signer_subject: string | null
  sign_mode: SignMode | null
  submitted_at: string | null
  reviewed_at: string | null
  review_comment: string | null
  created_at: string | null
  readiness?: { status: ReadyStatus; counts: Counts; on?: string }
  signature_valid?: boolean
}

export interface RequestWorker extends WorkerBrief {
  readiness: Readiness
  customer_decision: 'ok' | 'remark' | null
  customer_comment: string | null
}

export interface RequestFull extends RequestBrief {
  readiness: { status: ReadyStatus; counts: Counts; on: string }
  signature_valid: boolean
  reviewer: string | null
  workers: RequestWorker[]
  profile_items: ProfileItem[]
}

export interface DashboardDoc extends WorkerDocument {
  worker: { id: number; full_name: string; position: string }
}

export interface Dashboard {
  date: string
  today: string
  profile_id: number | null
  thresholds: number[]
  totals: { workers: number; ready: number; expiring: number; not_ready: number; docs_expiring: number; docs_expired: number }
  workers: (WorkerBrief & { readiness: Readiness })[]
  profiles: { id: number; name: string; customer: string | null; counts: Counts }[]
  expiring: DashboardDoc[]
  expired: DashboardDoc[]
  requests: RequestBrief[]
}

export interface NotificationItem {
  document_id: number
  worker_id: number
  worker: string
  position: string
  document: string
  short: string
  number: string | null
  valid_until: string
  days_left: number
  threshold: number
}

export interface NotificationLog {
  id: number
  channel: 'email' | 'telegram' | 'log'
  recipient: string | null
  subject: string
  body: string
  items: NotificationItem[]
  status: 'queued' | 'sent' | 'logged' | 'failed'
  error: string | null
  sent_at: string | null
  created_at: string | null
}

export interface ImportResult {
  dry_run: boolean
  workers_new: number
  workers_updated: number
  documents: number
  documents_skipped: number
  errors: string[]
  warnings: string[]
  preview: { iin: string; name: string; position: string; exists: boolean }[]
}

export interface SettingsData {
  organization: Organization
  users: User[]
  telegram_configured: boolean
  sign_mode: SignMode
  notify_time: string
  mailpit_url: string | null
}

export interface SignData {
  data: string
  data_base64: string
  hash: string
  sign_mode: SignMode
}
