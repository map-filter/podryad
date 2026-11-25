<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Logo from '@/components/Logo.vue'
import { login } from '@/stores/session'
import { errorMessage, fieldErrors } from '@/utils/errors'

const route = useRoute()
const router = useRouter()
const email = ref('')
const password = ref('')
const busy = ref(false)
const error = ref('')
const errors = ref<Record<string, string>>({})

const accounts = [
  { email: 'head@ertis.test', label: 'Руководитель подрядчика' },
  { email: 'ot@ertis.test', label: 'Специалист по ОТ' },
  { email: 'master@ertis.test', label: 'Мастер участка' },
  { email: 'ot.pnhz@zakazchik.test', label: 'Заказчик: ПНХЗ' },
  { email: 'ot.gres1@zakazchik.test', label: 'Заказчик: ГРЭС-1' },
  { email: 'ot.kez@zakazchik.test', label: 'Заказчик: КЭЗ (ERG)' },
]

function pick(value: string) {
  email.value = value
  password.value = 'portal2026'
}

async function submit() {
  busy.value = true
  error.value = ''
  errors.value = {}
  try {
    const user = await login(email.value.trim(), password.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null
    if (user.role === 'customer') router.replace(redirect?.startsWith('/customer') ? redirect : { name: 'customer-requests' })
    else router.replace(redirect && !redirect.startsWith('/customer') ? redirect : { name: 'dashboard' })
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errors.value.email ? '' : errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-card card">
      <div class="login-brand">
        <Logo :size="40" />
        <div>
          <h1>Кабинет допуска подрядчика</h1>
          <p class="muted">Удостоверения, медосмотры и сроки работников, пакеты допуска на объекты заказчика</p>
        </div>
      </div>
      <form class="stack" @submit.prevent="submit">
        <div class="field">
          <label for="email">Почта</label>
          <input id="email" v-model="email" class="input" :class="{ invalid: errors.email }" type="email" autocomplete="username" required />
          <span v-if="errors.email" class="field-error">{{ errors.email }}</span>
        </div>
        <div class="field">
          <label for="password">Пароль</label>
          <input id="password" v-model="password" class="input" type="password" autocomplete="current-password" required />
        </div>
        <div v-if="error" class="alert tone-danger">{{ error }}</div>
        <button class="btn btn-primary" type="submit" :disabled="busy">
          <span v-if="busy" class="spinner" />
          Войти
        </button>
      </form>
      <div class="accounts">
        <div class="field-label">Тестовые входы прототипа, пароль portal2026</div>
        <div class="account-list">
          <button v-for="a in accounts" :key="a.email" type="button" class="account" @click="pick(a.email)">
            <strong>{{ a.label }}</strong>
            <span>{{ a.email }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px 16px;
  background: linear-gradient(160deg, #13293d 0%, #1f4e79 55%, #2c6aa3 100%);
}

.login-card {
  width: 100%;
  max-width: 460px;
  padding: 26px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.login-brand {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}

.login-brand h1 {
  font-size: 20px;
}

.login-brand p {
  margin-top: 4px;
  font-size: 13.5px;
}

.accounts {
  border-top: 1px solid var(--c-border);
  padding-top: 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.account-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px;
}

.account {
  text-align: left;
  border: 1px solid var(--c-border);
  background: var(--c-surface-2);
  border-radius: var(--radius-sm);
  padding: 7px 10px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.account:hover {
  border-color: var(--c-accent);
}

.account strong {
  font-size: 13px;
}

.account span {
  font-size: 12px;
  color: var(--c-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (max-width: 480px) {
  .login-card {
    padding: 18px;
  }

  .account-list {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
