import { reactive } from 'vue'
import { api, getToken, setToken } from '@/api'
import type { AppConfig, Customer, Organization, User } from '@/api'

interface SessionState {
  config: AppConfig | null
  user: User | null
  organization: Organization | null
  customer: Customer | null
  loaded: boolean
}

export const session = reactive<SessionState>({
  config: null,
  user: null,
  organization: null,
  customer: null,
  loaded: false,
})

export function isAuthenticated(): boolean {
  return !!getToken()
}

export function isCustomer(): boolean {
  return session.user?.role === 'customer'
}

export async function loadConfig(): Promise<AppConfig | null> {
  if (session.config) return session.config
  try {
    session.config = await api.config()
  } catch {
    session.config = null
  }
  return session.config
}

export async function loadSession(force = false): Promise<void> {
  if (session.loaded && !force) return
  const [me] = await Promise.all([api.me(), loadConfig()])
  session.user = me.user
  session.organization = me.organization
  session.customer = me.customer
  session.loaded = true
}

export async function login(email: string, password: string): Promise<User> {
  const res = await api.login(email, password)
  setToken(res.token)
  session.user = res.user
  session.loaded = false
  await loadSession(true)
  return res.user
}

export function clearSession(): void {
  setToken(null)
  session.user = null
  session.organization = null
  session.customer = null
  session.loaded = false
}

export async function logout(): Promise<void> {
  try {
    await api.logout()
  } catch {
    void 0
  }
  clearSession()
}
