<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { logout, session } from '@/stores/session'
import Icon from './Icon.vue'
import Logo from './Logo.vue'

const route = useRoute()
const router = useRouter()
const menuOpen = ref(false)

const contractorNav = [
  { name: 'dashboard', label: 'Сводка', icon: 'home' },
  { name: 'workers', label: 'Работники', icon: 'users' },
  { name: 'requests', label: 'Заявки на допуск', icon: 'clipboard' },
  { name: 'objects', label: 'Объекты и профили', icon: 'building' },
  { name: 'types', label: 'Справочник документов', icon: 'book' },
  { name: 'import', label: 'Импорт из Excel', icon: 'upload' },
  { name: 'journal', label: 'Журнал уведомлений', icon: 'bell' },
  { name: 'settings', label: 'Настройки', icon: 'settings' },
  { name: 'help', label: 'Справка', icon: 'help' },
]

const customerNav = [
  { name: 'customer-requests', label: 'Заявки подрядчиков', icon: 'clipboard' },
  { name: 'help', label: 'Справка', icon: 'help' },
]

const isCustomer = computed(() => session.user?.role === 'customer')
const nav = computed(() => (isCustomer.value ? customerNav : contractorNav))

const activeName = computed(() => {
  const name = String(route.name ?? '')
  if (name === 'worker') return 'workers'
  if (name === 'request') return 'requests'
  if (name === 'customer-request') return 'customer-requests'
  return name
})

const signEmulated = computed(() => session.config?.sign_mode === 'emulate')
const orgName = computed(() => (isCustomer.value ? session.customer?.name : session.organization?.name) ?? 'Организация')
const orgSub = computed(() => (isCustomer.value ? 'кабинет заказчика' : session.organization?.bin ? `БИН ${session.organization.bin}` : ''))

watch(
  () => route.fullPath,
  () => (menuOpen.value = false),
)

async function onLogout() {
  await logout()
  router.replace({ name: 'login' })
}
</script>

<template>
  <div class="shell" :class="{ 'menu-open': menuOpen }">
    <aside class="sidebar" aria-label="Разделы">
      <div class="brand">
        <Logo :size="30" />
        <div class="brand-text">
          <strong>Допуск подрядчика</strong>
          <span>{{ isCustomer ? 'проверка заявок' : 'кабинет подрядчика' }}</span>
        </div>
        <button class="btn btn-ghost btn-icon close-menu" type="button" aria-label="Закрыть меню" @click="menuOpen = false">
          <Icon name="close" />
        </button>
      </div>
      <nav class="nav">
        <RouterLink
          v-for="item in nav"
          :key="item.name"
          :to="{ name: item.name }"
          class="nav-link"
          :class="{ active: activeName === item.name }"
        >
          <Icon :name="item.icon" />
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>
      <div class="side-foot">
        <div class="side-user">
          <Icon name="user" :size="16" />
          <span class="truncate">{{ session.user?.name }}</span>
        </div>
        <span class="side-role">{{ session.user?.role_label }}</span>
        <button class="btn btn-sm" type="button" @click="onLogout">
          <Icon name="logout" :size="15" />
          Выйти
        </button>
      </div>
    </aside>
    <div class="backdrop" @click="menuOpen = false" />

    <div class="main">
      <header class="topbar">
        <button class="btn btn-ghost btn-icon burger" type="button" aria-label="Меню" @click="menuOpen = true">
          <Icon name="menu" />
        </button>
        <div class="org">
          <strong class="truncate">{{ orgName }}</strong>
          <span v-if="orgSub" class="bin">{{ orgSub }}</span>
        </div>
        <div class="badges">
          <span v-if="signEmulated" class="badge tone-warning" title="Подпись списков выполняется без настоящего ключа ЭЦП">
            Подпись: эмуляция
          </span>
        </div>
        <div class="user">
          <span class="user-name truncate" :title="session.user?.role_label">{{ session.user?.name }}</span>
          <button class="btn btn-sm btn-ghost" type="button" title="Выйти" @click="onLogout">
            <Icon name="logout" :size="16" />
            <span class="logout-label">Выйти</span>
          </button>
        </div>
      </header>
      <main class="content">
        <slot />
      </main>
    </div>
  </div>
</template>

<style scoped>
.shell {
  min-height: 100vh;
  display: flex;
}

.sidebar {
  position: fixed;
  top: 0;
  left: 0;
  bottom: 0;
  width: var(--sidebar-w);
  background: #13293d;
  color: #d5e0ec;
  display: flex;
  flex-direction: column;
  z-index: 60;
  overflow-y: auto;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 16px 16px 14px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.brand :deep(rect:first-child) {
  fill: #2c6aa3;
}

.brand-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.brand-text strong {
  color: #fff;
  font-size: 15px;
  line-height: 1.2;
}

.brand-text span {
  font-size: 12px;
  color: #93a9c0;
}

.close-menu {
  display: none;
  color: #d5e0ec;
}

.nav {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 12px 10px;
  flex: 1;
}

.nav-link {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 9px 12px;
  border-radius: 8px;
  color: #c3d2e2;
  text-decoration: none;
  font-size: 14.5px;
  font-weight: 500;
}

.nav-link:hover {
  background: rgba(255, 255, 255, 0.06);
  color: #fff;
}

.nav-link.active {
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  font-weight: 600;
}

.nav-link .ico {
  flex: none;
  opacity: 0.9;
}

.side-foot {
  display: none;
  padding: 12px 16px 18px;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  gap: 10px;
  flex-direction: column;
}

.side-user {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.side-foot .btn {
  background: transparent;
  color: #d5e0ec;
  border-color: rgba(255, 255, 255, 0.25);
  align-self: flex-start;
}

.side-role {
  font-size: 12px;
  color: #93a9c0;
}

.backdrop {
  display: none;
}

.main {
  flex: 1;
  min-width: 0;
  margin-left: var(--sidebar-w);
  display: flex;
  flex-direction: column;
}

.topbar {
  position: sticky;
  top: 0;
  z-index: 40;
  height: var(--header-h);
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 24px;
  background: rgba(255, 255, 255, 0.96);
  border-bottom: 1px solid var(--c-border);
  backdrop-filter: blur(6px);
}

.burger {
  display: none;
  margin-left: -6px;
}

.org {
  display: flex;
  align-items: baseline;
  gap: 10px;
  min-width: 0;
  flex: 1 1 auto;
}

.org strong {
  font-size: 15px;
}

.bin {
  color: var(--c-muted);
  font-size: 13px;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.badges {
  display: flex;
  gap: 6px;
  flex: none;
}

.badges a {
  text-decoration: none;
}

.user {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
  flex: 0 1 auto;
}

.user-name {
  max-width: 180px;
  font-size: 14px;
  color: var(--c-text-2);
}

.content {
  padding: 24px;
  width: 100%;
  max-width: 1320px;
  min-width: 0;
}

@media (max-width: 1180px) {
  .user-name {
    display: none;
  }
}

@media (max-width: 900px) {
  .sidebar {
    transform: translateX(-100%);
    transition: transform 0.2s ease;
    width: min(300px, 86vw);
    box-shadow: var(--shadow-lg);
  }

  .menu-open .sidebar {
    transform: none;
  }

  .menu-open .backdrop {
    display: block;
    position: fixed;
    inset: 0;
    background: rgba(10, 20, 32, 0.45);
    z-index: 55;
  }

  .close-menu {
    display: inline-flex;
  }

  .side-foot {
    display: flex;
  }

  .main {
    margin-left: 0;
  }

  .burger {
    display: inline-flex;
  }

  .topbar {
    padding: 0 16px;
  }

  .user {
    display: none;
  }

  .content {
    padding: 18px 16px;
  }
}

@media (max-width: 560px) {
  .topbar {
    height: auto;
    min-height: var(--header-h);
    flex-wrap: wrap;
    padding: 8px 16px;
    row-gap: 4px;
  }

  .org {
    flex-direction: column;
    gap: 0;
    flex: 1 1 0;
  }

  .org strong {
    max-width: 100%;
  }

  .badges {
    flex: 1 1 100%;
    flex-wrap: wrap;
    order: 3;
  }

  .badges:empty {
    display: none;
  }
}
</style>
