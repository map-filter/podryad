import { api } from '@/api'
import type { SignMode } from '@/api'

export interface SignOptions {
  attached: boolean
}

export interface Signer {
  readonly kind: SignMode
  signCms(dataBase64: string, opts: SignOptions): Promise<string>
}

export type SignErrorReason = 'not_running' | 'cancelled' | 'wrong_password' | 'timeout' | 'failed'

export class SignError extends Error {
  reason: SignErrorReason

  constructor(reason: SignErrorReason, message: string) {
    super(message)
    this.name = 'SignError'
    this.reason = reason
  }
}

export const NCALAYER_URL = 'wss://127.0.0.1:13579/'

const CONNECT_TIMEOUT_MS = 3000
const SIGN_TIMEOUT_MS = 5 * 60 * 1000

const BASICS_MODULE = 'kz.gov.pki.knca.basics'
const LEGACY_MODULE = 'kz.gov.pki.knca.commonUtils'

const NOT_RUNNING_MESSAGE =
  'Не удалось подключиться к NCALayer. Запустите NCALayer и обновите страницу. ' +
  'Если NCALayer уже запущен, один раз откройте https://127.0.0.1:13579 в этом браузере и разрешите подключение (браузер должен доверять этому адресу).'

class ModuleMissing extends Error {
  constructor() {
    super('module not found')
  }
}

interface Pending {
  resolve: (value: unknown) => void
  reject: (reason: SignError) => void
  timer: ReturnType<typeof setTimeout>
}

function textOf(value: unknown): string {
  if (typeof value === 'string') return value
  if (value === null || value === undefined) return ''
  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

function classify(code: unknown, message: unknown): SignError {
  const text = `${textOf(code)} ${textOf(message)}`.toLowerCase()
  if (/cancel|отмен|abort/.test(text)) {
    return new SignError('cancelled', 'Подпись отменена. Можно попробовать ещё раз')
  }
  if (/password|парол|pin|bad_key|wrong key/.test(text)) {
    return new SignError('wrong_password', 'Неверный пароль от ключа ЭЦП. Проверьте пароль и попробуйте ещё раз')
  }
  if (/storage|носител|token not found|empty/.test(text)) {
    return new SignError('failed', 'Не найден ключ ЭЦП на выбранном носителе. Выберите файл ключа или подключите токен')
  }
  const details = textOf(message).trim()
  return new SignError('failed', details ? `NCALayer вернул ошибку: ${details}` : 'NCALayer не смог подписать данные')
}

function isModuleMissing(code: unknown, message: unknown): boolean {
  const text = `${textOf(code)} ${textOf(message)}`.toLowerCase()
  return /module/.test(text) && /(not found|not_found|notfound|не найден|unknown|unavailable|not available|no such)/.test(text)
}

function isGreeting(msg: Record<string, unknown>): boolean {
  if ('status' in msg || 'code' in msg || 'responseObject' in msg) return false
  const result = msg.result
  return !!result && typeof result === 'object' && !Array.isArray(result) && 'version' in (result as object)
}

export class NcaLayerSigner implements Signer {
  readonly kind = 'ncalayer' as const
  private socket: WebSocket | null = null
  private connecting: Promise<WebSocket> | null = null
  private pending: Pending[] = []
  private chain: Promise<unknown> = Promise.resolve()
  private legacyOnly = false
  version: string | null = null

  constructor(private readonly url: string = NCALAYER_URL) {}

  private connect(): Promise<WebSocket> {
    if (this.socket && this.socket.readyState === WebSocket.OPEN) return Promise.resolve(this.socket)
    if (this.connecting) return this.connecting
    this.connecting = new Promise<WebSocket>((resolve, reject) => {
      let settled = false
      let ws: WebSocket
      try {
        ws = new WebSocket(this.url)
      } catch {
        this.connecting = null
        reject(new SignError('not_running', NOT_RUNNING_MESSAGE))
        return
      }
      const timer = setTimeout(() => {
        if (settled) return
        settled = true
        this.connecting = null
        try {
          ws.close()
        } catch {
          void 0
        }
        reject(new SignError('not_running', NOT_RUNNING_MESSAGE))
      }, CONNECT_TIMEOUT_MS)
      ws.onopen = () => {
        if (settled) return
        settled = true
        clearTimeout(timer)
        this.socket = ws
        this.connecting = null
        resolve(ws)
      }
      ws.onerror = () => {
        if (settled) return
        settled = true
        clearTimeout(timer)
        this.connecting = null
        reject(new SignError('not_running', NOT_RUNNING_MESSAGE))
      }
      ws.onclose = () => {
        if (this.socket === ws) this.socket = null
        this.failAll(new SignError('not_running', 'Соединение с NCALayer прервалось. Проверьте, что NCALayer запущен, и попробуйте ещё раз'))
        if (!settled) {
          settled = true
          clearTimeout(timer)
          this.connecting = null
          reject(new SignError('not_running', NOT_RUNNING_MESSAGE))
        }
      }
      ws.onmessage = (event: MessageEvent) => this.handleMessage(event.data)
    })
    return this.connecting
  }

  private handleMessage(raw: unknown): void {
    let msg: unknown
    try {
      msg = JSON.parse(String(raw))
    } catch {
      msg = raw
    }
    if (msg && typeof msg === 'object' && isGreeting(msg as Record<string, unknown>)) {
      const version = (msg as { result: { version?: unknown } }).result.version
      this.version = typeof version === 'string' ? version : textOf(version)
      return
    }
    const next = this.pending.shift()
    if (!next) return
    clearTimeout(next.timer)
    next.resolve(msg)
  }

  private failAll(error: SignError): void {
    const list = this.pending
    this.pending = []
    for (const p of list) {
      clearTimeout(p.timer)
      p.reject(error)
    }
  }

  private async call(message: unknown): Promise<unknown> {
    const ws = await this.connect()
    return new Promise<unknown>((resolve, reject) => {
      const entry: Pending = {
        resolve,
        reject,
        timer: setTimeout(() => {
          const index = this.pending.indexOf(entry)
          if (index >= 0) this.pending.splice(index, 1)
          reject(new SignError('timeout', 'NCALayer не ответил вовремя. Закройте окно выбора ключа и попробуйте ещё раз'))
          try {
            ws.close()
          } catch {
            void 0
          }
        }, SIGN_TIMEOUT_MS),
      }
      this.pending.push(entry)
      try {
        ws.send(JSON.stringify(message))
      } catch {
        this.pending.splice(this.pending.indexOf(entry), 1)
        clearTimeout(entry.timer)
        reject(new SignError('not_running', NOT_RUNNING_MESSAGE))
      }
    })
  }

  private enqueue<T>(task: () => Promise<T>): Promise<T> {
    const run = this.chain.then(task, task)
    this.chain = run.catch(() => undefined)
    return run
  }

  private async signBasics(dataBase64: string, attached: boolean): Promise<string> {
    const response = await this.call({
      module: BASICS_MODULE,
      method: 'sign',
      args: {
        allowedStorages: ['PKCS12', 'AKKaztokenStore', 'AKKZIDCardStore', 'AKEToken72KStore', 'AKJaCartaStore'],
        format: 'cms',
        data: dataBase64,
        signingParams: { decode: true, encapsulate: attached, digested: false, tsaProfile: {} },
        signerParams: { extKeyUsageOids: ['1.3.6.1.5.5.7.3.4'], chain: [] },
        locale: 'ru',
      },
    })
    const r = (response ?? {}) as { status?: unknown; code?: unknown; message?: unknown; body?: { result?: unknown } }
    if (r.status === true) {
      const result = r.body?.result
      if (Array.isArray(result) && typeof result[0] === 'string' && result[0]) return result[0]
      if (typeof result === 'string' && result) return result
      throw new SignError('failed', 'NCALayer вернул пустую подпись')
    }
    if (isModuleMissing(r.code, r.message)) throw new ModuleMissing()
    throw classify(r.code, r.message)
  }

  private async signLegacy(dataBase64: string, attached: boolean): Promise<string> {
    const response = await this.call({
      module: LEGACY_MODULE,
      method: 'createCAdESFromBase64',
      args: ['PKCS12', 'SIGNATURE', dataBase64, attached],
    })
    const r = (response ?? {}) as { code?: unknown; message?: unknown; responseObject?: unknown }
    if (String(r.code) === '200' && typeof r.responseObject === 'string' && r.responseObject) {
      return r.responseObject
    }
    throw classify(r.code, r.message)
  }

  signCms(dataBase64: string, opts: SignOptions): Promise<string> {
    return this.enqueue(async () => {
      if (!this.legacyOnly) {
        try {
          return await this.signBasics(dataBase64, opts.attached)
        } catch (e) {
          if (!(e instanceof ModuleMissing)) throw e
          this.legacyOnly = true
        }
      }
      return this.signLegacy(dataBase64, opts.attached)
    })
  }
}

export class EmulatedSigner implements Signer {
  readonly kind = 'emulate' as const

  async signCms(dataBase64: string, _opts: SignOptions): Promise<string> {
    void _opts
    const { signature } = await api.signEmulate(dataBase64)
    return signature
  }
}

let current: { mode: SignMode; signer: Signer } | null = null

export function getSigner(mode: SignMode): Signer {
  if (current && current.mode === mode) return current.signer
  const signer: Signer = mode === 'ncalayer' ? new NcaLayerSigner() : new EmulatedSigner()
  current = { mode, signer }
  return signer
}

export function utf8ToBase64(text: string): string {
  const bytes = new TextEncoder().encode(text)
  let binary = ''
  const chunk = 0x8000
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode(...bytes.subarray(i, i + chunk))
  }
  return btoa(binary)
}
