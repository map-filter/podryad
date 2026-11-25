const TOKEN_KEY = 'podryad.token'

let unauthorizedHandler: (() => void) | null = null

export function onUnauthorized(handler: () => void): void {
  unauthorizedHandler = handler
}

export function getToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function setToken(token: string | null): void {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    return
  }
}

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>
  payload: unknown

  constructor(status: number, message: string, errors: Record<string, string[]> = {}, payload: unknown = null) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
    this.payload = payload
  }

  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

type Query = Record<string, string | number | boolean | null | undefined>

export interface RequestOptions {
  method?: string
  query?: Query
  body?: unknown
  token?: string | null
  auth?: boolean
  signal?: AbortSignal
}

export function buildUrl(path: string, query?: Query): string {
  const url = path.startsWith('http') ? path : `/api${path}`
  if (!query) return url
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === '') continue
    params.append(key, String(value))
  }
  const qs = params.toString()
  return qs ? `${url}${url.includes('?') ? '&' : '?'}${qs}` : url
}

function statusMessage(status: number): string {
  if (status === 403) return 'Недостаточно прав для этого действия'
  if (status === 404) return 'Не найдено'
  if (status === 409) return 'Действие сейчас недоступно'
  if (status === 413) return 'Файл слишком большой'
  if (status === 429) return 'Слишком много запросов, попробуйте через минуту'
  if (status >= 500) return 'Ошибка сервера, попробуйте ещё раз чуть позже'
  return `Ошибка запроса (${status})`
}

async function toError(response: Response): Promise<ApiError> {
  let payload: unknown = null
  const type = response.headers.get('content-type') ?? ''
  try {
    if (type.includes('application/json')) payload = await response.json()
    else {
      const text = await response.text()
      payload = text
    }
  } catch {
    payload = null
  }
  let message = statusMessage(response.status)
  let errors: Record<string, string[]> = {}
  if (payload && typeof payload === 'object') {
    const p = payload as { message?: unknown; errors?: unknown; error?: unknown }
    if (typeof p.message === 'string' && p.message.trim() && !(response.status >= 500 && p.message === 'Server Error')) {
      message = p.message
    } else if (typeof p.error === 'string' && p.error.trim()) {
      message = p.error
    }
    if (p.errors && typeof p.errors === 'object' && !Array.isArray(p.errors)) {
      errors = p.errors as Record<string, string[]>
    }
  }
  return new ApiError(response.status, message, errors, payload)
}

async function send(path: string, options: RequestOptions, accept: string): Promise<Response> {
  const headers: Record<string, string> = { Accept: accept }
  const useAuth = options.auth !== false
  const token = options.token !== undefined ? options.token : useAuth ? getToken() : null
  if (token) headers.Authorization = `Bearer ${token}`
  let body: BodyInit | undefined
  if (options.body instanceof FormData) {
    body = options.body
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(options.body)
  }
  let response: Response
  try {
    response = await fetch(buildUrl(path, options.query), {
      method: options.method ?? 'GET',
      headers,
      body,
      signal: options.signal,
    })
  } catch (e) {
    if (e instanceof DOMException && e.name === 'AbortError') throw e
    throw new ApiError(0, 'Нет связи с сервером. Проверьте интернет и попробуйте ещё раз')
  }
  if (!response.ok) {
    const error = await toError(response)
    if (response.status === 401 && useAuth && options.token === undefined) {
      setToken(null)
      unauthorizedHandler?.()
    }
    throw error
  }
  return response
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const response = await send(path, options, 'application/json')
  if (response.status === 204) return undefined as T
  const type = response.headers.get('content-type') ?? ''
  if (!type.includes('json')) {
    const text = await response.text()
    return (text ? (JSON.parse(text) as T) : (undefined as T))
  }
  return (await response.json()) as T
}

export interface BlobResult {
  blob: Blob
  filename: string | null
}

function filenameFrom(header: string | null): string | null {
  if (!header) return null
  const star = /filename\*\s*=\s*(?:UTF-8'')?([^;]+)/i.exec(header)
  if (star) {
    try {
      return decodeURIComponent(star[1].trim().replace(/^"|"$/g, ''))
    } catch {
      return star[1].trim()
    }
  }
  const plain = /filename\s*=\s*"?([^";]+)"?/i.exec(header)
  return plain ? plain[1].trim() : null
}

export async function requestBlob(path: string, options: RequestOptions = {}): Promise<BlobResult> {
  const response = await send(path, options, '*/*')
  const blob = await response.blob()
  return { blob, filename: filenameFrom(response.headers.get('content-disposition')) }
}

export function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 30000)
}

export function openBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const win = window.open(url, '_blank')
  if (!win) saveBlob(blob, filename)
  setTimeout(() => URL.revokeObjectURL(url), 120000)
}
