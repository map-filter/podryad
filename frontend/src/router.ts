import { createRouter, createWebHistory } from 'vue-router'
import { onUnauthorized } from '@/api/http'
import { clearSession, isAuthenticated, isCustomer, loadConfig, loadSession } from '@/stores/session'

const titles: Record<string, string> = {
  login: 'Вход',
  dashboard: 'Сводка',
  workers: 'Работники',
  worker: 'Карточка работника',
  import: 'Импорт из Excel',
  types: 'Справочник документов',
  objects: 'Объекты и профили',
  requests: 'Заявки на допуск',
  request: 'Заявка на допуск',
  journal: 'Журнал уведомлений',
  settings: 'Настройки',
  help: 'Справка',
  'customer-requests': 'Заявки подрядчиков',
  'customer-request': 'Проверка заявки',
}

export const router = createRouter({
  history: createWebHistory('/'),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
    { path: '/', name: 'dashboard', component: () => import('@/views/DashboardView.vue') },
    { path: '/workers', name: 'workers', component: () => import('@/views/WorkersView.vue') },
    { path: '/workers/:id(\\d+)', name: 'worker', component: () => import('@/views/WorkerView.vue') },
    { path: '/import', name: 'import', component: () => import('@/views/ImportView.vue') },
    { path: '/document-types', name: 'types', component: () => import('@/views/DocumentTypesView.vue') },
    { path: '/objects', name: 'objects', component: () => import('@/views/ObjectsView.vue') },
    { path: '/requests', name: 'requests', component: () => import('@/views/RequestsView.vue') },
    { path: '/requests/:id(\\d+)', name: 'request', component: () => import('@/views/RequestView.vue') },
    { path: '/journal', name: 'journal', component: () => import('@/views/JournalView.vue') },
    { path: '/settings', name: 'settings', component: () => import('@/views/SettingsView.vue') },
    { path: '/help', name: 'help', component: () => import('@/views/HelpView.vue') },
    { path: '/customer', name: 'customer-requests', component: () => import('@/views/CustomerRequestsView.vue'), meta: { customer: true } },
    { path: '/customer/requests/:id(\\d+)', name: 'customer-request', component: () => import('@/views/CustomerRequestView.vue'), meta: { customer: true } },
    { path: '/:pathMatch(.*)*', redirect: { name: 'dashboard' } },
  ],
})

router.beforeEach(async (to) => {
  if (to.meta.public) {
    await loadConfig()
    if (to.name === 'login' && isAuthenticated()) {
      try {
        await loadSession()
        return isCustomer() ? { name: 'customer-requests' } : { name: 'dashboard' }
      } catch {
        clearSession()
      }
    }
    return true
  }
  if (!isAuthenticated()) {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }
  try {
    await loadSession()
  } catch {
    if (!isAuthenticated()) return { name: 'login' }
  }
  if (isCustomer() && !to.meta.customer && to.name !== 'help') return { name: 'customer-requests' }
  if (!isCustomer() && to.meta.customer) return { name: 'dashboard' }
  return true
})

router.afterEach((to) => {
  const title = titles[String(to.name ?? '')]
  document.title = title ? `${title} · Кабинет допуска` : 'Кабинет допуска подрядчика'
})

onUnauthorized(() => {
  clearSession()
  const current = router.currentRoute.value
  if (current.name !== 'login') {
    router.replace({ name: 'login', query: current.fullPath !== '/' ? { redirect: current.fullPath } : {} })
  }
})
