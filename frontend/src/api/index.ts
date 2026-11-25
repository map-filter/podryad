import { getToken, request, requestBlob } from './http'
import type {
  AppConfig,
  Customer,
  Dashboard,
  DocumentType,
  Facility,
  ImportResult,
  Me,
  NotificationLog,
  Organization,
  Profile,
  RequestBrief,
  RequestFull,
  SettingsData,
  SignData,
  User,
  WorkerCard,
  WorkerDocument,
  WorkerRow,
} from './types'

export * from './types'
export { ApiError, getToken, saveBlob, openBlob, setToken } from './http'

type Q = Record<string, string | number | boolean | null | undefined>

export function fileUrl(path: string, version?: number | string | null): string {
  const params = new URLSearchParams()
  const token = getToken()
  if (token) params.set('token', token)
  if (version) params.set('v', String(version))
  return `/api${path}?${params.toString()}`
}

export const api = {
  config: () => request<AppConfig>('/config', { auth: false }),
  login: (email: string, password: string) =>
    request<{ token: string; user: User }>('/auth/login', { method: 'POST', body: { email, password }, auth: false }),
  logout: () => request<void>('/auth/logout', { method: 'POST' }),
  me: () => request<Me>('/me'),

  dashboard: (query: Q = {}) => request<Dashboard>('/dashboard', { query }),

  workers: (query: Q = {}) => request<{ data: WorkerRow[]; meta: { sites: string[]; positions: string[] } }>('/workers', { query }),
  worker: (id: number) => request<WorkerCard>(`/workers/${id}`),
  createWorker: (body: Record<string, unknown>) => request<WorkerCard>('/workers', { method: 'POST', body }),
  updateWorker: (id: number, body: Record<string, unknown>) => request<WorkerCard>(`/workers/${id}`, { method: 'PUT', body }),
  deleteWorker: (id: number) => request<{ archived?: boolean; deleted?: boolean }>(`/workers/${id}`, { method: 'DELETE' }),
  uploadPhoto: (id: number, file: File) => {
    const fd = new FormData()
    fd.append('photo', file)
    return request<WorkerCard>(`/workers/${id}/photo`, { method: 'POST', body: fd })
  },
  saveDocument: (workerId: number, docId: number | null, fd: FormData) =>
    request<WorkerDocument>(docId ? `/documents/${docId}` : `/workers/${workerId}/documents`, { method: 'POST', body: fd }),
  deleteDocument: (id: number) => request<void>(`/documents/${id}`, { method: 'DELETE' }),

  documentTypes: () => request<{ data: DocumentType[] }>('/document-types'),
  saveDocumentType: (id: number | null, body: Record<string, unknown>) =>
    request<DocumentType>(id ? `/document-types/${id}` : '/document-types', { method: id ? 'PUT' : 'POST', body }),
  deleteDocumentType: (id: number) => request<void>(`/document-types/${id}`, { method: 'DELETE' }),

  customers: () => request<{ data: Customer[] }>('/customers'),
  saveCustomer: (id: number | null, body: Record<string, unknown>) =>
    request<Customer>(id ? `/customers/${id}` : '/customers', { method: id ? 'PUT' : 'POST', body }),
  saveFacility: (id: number | null, body: Record<string, unknown>) =>
    request<Facility>(id ? `/facilities/${id}` : '/facilities', { method: id ? 'PUT' : 'POST', body }),
  deleteFacility: (id: number) => request<void>(`/facilities/${id}`, { method: 'DELETE' }),

  profiles: () => request<{ data: Profile[] }>('/profiles'),
  saveProfile: (id: number | null, body: Record<string, unknown>) =>
    request<Profile>(id ? `/profiles/${id}` : '/profiles', { method: id ? 'PUT' : 'POST', body }),
  deleteProfile: (id: number) => request<void>(`/profiles/${id}`, { method: 'DELETE' }),
  profileReadiness: (id: number, date?: string) =>
    request<{ date: string; data: (WorkerRow & { readiness: WorkerRow['readiness'] })[] }>(`/profiles/${id}/readiness`, { query: { date } }),

  requests: (query: Q = {}) => request<{ data: RequestBrief[] }>('/requests', { query }),
  request: (id: number) => request<RequestFull>(`/requests/${id}`),
  createRequest: (body: Record<string, unknown>) => request<RequestFull>('/requests', { method: 'POST', body }),
  updateRequest: (id: number, body: Record<string, unknown>) => request<RequestFull>(`/requests/${id}`, { method: 'PUT', body }),
  deleteRequest: (id: number) => request<void>(`/requests/${id}`, { method: 'DELETE' }),
  requestWorkers: (id: number, add: number[], remove: number[]) =>
    request<RequestFull>(`/requests/${id}/workers`, { method: 'POST', body: { add, remove } }),
  signData: (id: number) => request<SignData>(`/requests/${id}/sign-data`),
  sign: (id: number, signature: string) => request<RequestFull>(`/requests/${id}/sign`, { method: 'POST', body: { signature } }),
  signEmulate: (data: string) => request<{ signature: string }>('/sign/emulate', { method: 'POST', body: { data } }),
  submit: (id: number) => request<RequestFull>(`/requests/${id}/submit`, { method: 'POST' }),
  download: (path: string) => requestBlob(path),

  importFile: (file: File, dryRun: boolean) => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('dry_run', dryRun ? '1' : '0')
    return request<ImportResult>('/import', { method: 'POST', body: fd })
  },

  notifications: (query: Q = {}) =>
    request<{ data: NotificationLog[]; meta: { total: number; page: number; last_page: number } }>('/notifications', { query }),
  runNotifications: () => request<{ created: number; items: number; data: NotificationLog[] }>('/notifications/run', { method: 'POST' }),

  settings: () => request<SettingsData>('/settings'),
  saveSettings: (body: Record<string, unknown>) => request<{ organization: Organization }>('/settings', { method: 'PUT', body }),
  createUser: (body: Record<string, unknown>) => request<User>('/settings/users', { method: 'POST', body }),
  testTelegram: () => request<NotificationLog>('/settings/telegram-test', { method: 'POST' }),

  customerRequests: (query: Q = {}) => request<{ data: RequestBrief[] }>('/customer/requests', { query }),
  customerRequest: (id: number) => request<RequestFull>(`/customer/requests/${id}`),
  customerDecision: (id: number, body: Record<string, unknown>) =>
    request<RequestFull>(`/customer/requests/${id}/decision`, { method: 'POST', body }),
}
