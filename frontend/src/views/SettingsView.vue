<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api'
import type { SettingsData } from '@/api'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { loadSession, session } from '@/stores/session'
import { notify, notifyError } from '@/stores/toast'
import { errorMessage, fieldErrors } from '@/utils/errors'

const data = ref<SettingsData | null>(null)
const form = reactive({
  name: '',
  short_name: '',
  bin: '',
  city: '',
  address: '',
  director: '',
  phone: '',
  email: '',
  notify_email: '',
  notify_email_enabled: true,
  telegram_chat_id: '',
  notify_telegram_enabled: true,
  thresholds: '30, 14, 7',
})
const errors = ref<Record<string, string>>({})
const saving = ref(false)
const canManage = computed(() => !!session.user?.can_manage)
const userModal = ref(false)
const uform = reactive({ name: '', email: '', role: 'foreman', position: '', password: '' })
const uerrors = ref<Record<string, string>>({})
const uerror = ref('')

async function load() {
  try {
    data.value = await api.settings()
    const o = data.value.organization
    Object.assign(form, {
      name: o.name,
      short_name: o.short_name ?? '',
      bin: o.bin,
      city: o.city ?? '',
      address: o.address ?? '',
      director: o.director ?? '',
      phone: o.phone ?? '',
      email: o.email ?? '',
      notify_email: o.notify_email ?? '',
      notify_email_enabled: o.notify_email_enabled,
      telegram_chat_id: o.telegram_chat_id ?? '',
      notify_telegram_enabled: o.notify_telegram_enabled,
      thresholds: o.notify_thresholds.join(', '),
    })
  } catch (e) {
    notifyError(e)
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const { thresholds, ...rest } = form
    await api.saveSettings({
      ...rest,
      notify_thresholds: thresholds.split(/[,;\s]+/).filter(Boolean).map(Number),
    })
    notify('Настройки сохранены')
    await loadSession(true)
    load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) notifyError(e)
  } finally {
    saving.value = false
  }
}

async function testTelegram() {
  try {
    const res = await api.testTelegram()
    notify(res.status === 'sent' ? 'Сообщение отправлено в Telegram' : res.error ?? 'Записано в журнал', res.status === 'sent' ? 'success' : 'info')
  } catch (e) {
    notifyError(e)
  }
}

async function addUser() {
  uerrors.value = {}
  uerror.value = ''
  try {
    await api.createUser({ ...uform })
    userModal.value = false
    notify('Пользователь добавлен')
    load()
  } catch (e) {
    uerrors.value = fieldErrors(e)
    if (!Object.keys(uerrors.value).length) uerror.value = errorMessage(e)
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div>
        <h1>Настройки</h1>
        <p>Организация, уведомления о сроках и пользователи кабинета.</p>
      </div>
    </div>
    <div v-if="!data" class="card loading-line">Загрузка...</div>
    <template v-else>
      <form class="grid grid-2" @submit.prevent="save">
        <div class="card">
          <div class="card-head"><h2>Организация</h2></div>
          <div class="card-body form-grid">
            <div class="field span-2">
              <label for="s-name">Название</label>
              <input id="s-name" v-model="form.name" class="input" :disabled="!canManage" />
              <span v-if="errors.name" class="field-error">{{ errors.name }}</span>
            </div>
            <div class="field">
              <label for="s-bin">БИН</label>
              <input id="s-bin" v-model="form.bin" class="input mono" maxlength="12" :class="{ invalid: errors.bin }" :disabled="!canManage" />
              <span v-if="errors.bin" class="field-error">{{ errors.bin }}</span>
            </div>
            <div class="field">
              <label for="s-city">Город</label>
              <input id="s-city" v-model="form.city" class="input" :disabled="!canManage" />
            </div>
            <div class="field span-2">
              <label for="s-addr">Адрес</label>
              <input id="s-addr" v-model="form.address" class="input" :disabled="!canManage" />
            </div>
            <div class="field">
              <label for="s-dir">Руководитель</label>
              <input id="s-dir" v-model="form.director" class="input" :disabled="!canManage" />
            </div>
            <div class="field">
              <label for="s-phone">Телефон</label>
              <input id="s-phone" v-model="form.phone" class="input" :disabled="!canManage" />
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-head"><h2>Уведомления о сроках</h2></div>
          <div class="card-body form-grid">
            <div class="field span-2">
              <label for="s-th">Пороги, дней до окончания</label>
              <input id="s-th" v-model="form.thresholds" class="input" :disabled="!canManage" />
              <span v-if="errors.notify_thresholds" class="field-error">{{ errors.notify_thresholds }}</span>
              <span v-else class="field-hint">Через запятую. Уведомление уходит один раз на каждый порог и при просрочке. Проверка каждый день в {{ data.notify_time }}.</span>
            </div>
            <label class="check span-2"><input v-model="form.notify_email_enabled" type="checkbox" :disabled="!canManage" /> Письма на почту</label>
            <div class="field span-2">
              <label for="s-nemail">Почта для сводки</label>
              <input id="s-nemail" v-model="form.notify_email" class="input" type="email" :disabled="!canManage" />
              <span class="field-hint">Плюс все руководители и специалисты по ОТ из списка пользователей</span>
            </div>
            <label class="check span-2"><input v-model="form.notify_telegram_enabled" type="checkbox" :disabled="!canManage" /> Сообщения в Telegram</label>
            <div class="field span-2">
              <label for="s-tg">Чат Telegram (chat_id)</label>
              <input id="s-tg" v-model="form.telegram_chat_id" class="input" :class="{ invalid: errors.telegram_chat_id }" placeholder="-1001234567890" :disabled="!canManage" />
              <span v-if="errors.telegram_chat_id" class="field-error">{{ errors.telegram_chat_id }}</span>
              <span v-else class="field-hint">
                {{ data.telegram_configured ? 'Токен бота задан в .env.' : 'Токен бота не задан в .env (TELEGRAM_BOT_TOKEN): сообщения пишутся в журнал.' }}
              </span>
            </div>
            <div v-if="canManage" class="span-2 row">
              <button class="btn btn-primary" type="submit" :disabled="saving"><span v-if="saving" class="spinner" />Сохранить настройки</button>
              <button class="btn" type="button" @click="testTelegram"><Icon name="telegram" />Проверить Telegram</button>
            </div>
          </div>
        </div>
      </form>

      <div class="card">
        <div class="card-head">
          <h2>Пользователи</h2>
          <button v-if="canManage" class="btn btn-sm" type="button" @click="userModal = true"><Icon name="plus" :size="15" />Добавить</button>
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Имя</th><th>Почта</th><th>Роль</th></tr></thead>
            <tbody>
              <tr v-for="u in data.users" :key="u.id">
                <td><div class="cell-title">{{ u.name }}</div><div class="cell-sub">{{ u.position }}</div></td>
                <td>{{ u.email }}</td>
                <td><span class="badge plain tone-accent">{{ u.role_label }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card card-body stack">
        <h2>Подпись ЭЦП</h2>
        <p class="text-2">
          Режим: <strong>{{ data.sign_mode === 'emulate' ? 'эмуляция (без настоящего ключа)' : 'NCALayer' }}</strong>.
          В боевом режиме (SIGN_MODE=ncalayer в .env) кабинет обращается к NCALayer на компьютере пользователя по wss://127.0.0.1:13579, модуль kz.gov.pki.knca.basics (запасной commonUtils), формат CMS. Ключ не покидает компьютер, на сервер уходит только подпись.
        </p>
      </div>
    </template>

    <Modal v-if="userModal" title="Новый пользователь" :width="520" @close="userModal = false">
      <form id="user-form" class="form-grid" @submit.prevent="addUser">
        <div class="field span-2">
          <label for="u-name">ФИО</label>
          <input id="u-name" v-model="uform.name" class="input" required />
          <span v-if="uerrors.name" class="field-error">{{ uerrors.name }}</span>
        </div>
        <div class="field span-2">
          <label for="u-email">Почта (логин)</label>
          <input id="u-email" v-model="uform.email" class="input" type="email" required />
          <span v-if="uerrors.email" class="field-error">{{ uerrors.email }}</span>
        </div>
        <div class="field">
          <label for="u-role">Роль</label>
          <select id="u-role" v-model="uform.role" class="select">
            <option value="head">Руководитель</option>
            <option value="safety">Специалист по ОТ</option>
            <option value="foreman">Мастер</option>
          </select>
        </div>
        <div class="field">
          <label for="u-pos">Должность</label>
          <input id="u-pos" v-model="uform.position" class="input" />
        </div>
        <div class="field span-2">
          <label for="u-pass">Пароль</label>
          <input id="u-pass" v-model="uform.password" class="input" type="password" autocomplete="new-password" minlength="8" required />
          <span v-if="uerrors.password" class="field-error">{{ uerrors.password }}</span>
        </div>
        <div v-if="uerror" class="alert tone-danger span-2">{{ uerror }}</div>
      </form>
      <template #footer>
        <button class="btn" type="button" @click="userModal = false">Отмена</button>
        <button class="btn btn-primary" type="submit" form="user-form">Добавить</button>
      </template>
    </Modal>
  </div>
</template>
