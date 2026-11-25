<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { api } from '@/api'
import type { NotificationLog } from '@/api'
import Icon from '@/components/Icon.vue'
import { session } from '@/stores/session'
import { notify, notifyError } from '@/stores/toast'
import { formatDateTime } from '@/utils/format'

const rows = ref<NotificationLog[]>([])
const total = ref(0)
const page = ref(1)
const lastPage = ref(1)
const channel = ref('')
const loading = ref(true)
const running = ref(false)
const opened = ref<number | null>(null)

const channelLabels: Record<string, string> = { email: 'Почта', telegram: 'Telegram', log: 'Журнал' }
const statusLabels: Record<string, string> = { queued: 'в очереди', sent: 'отправлено', logged: 'записано в журнал', failed: 'ошибка' }
const statusTones: Record<string, string> = { queued: 'tone-info', sent: 'tone-success', logged: 'tone-muted', failed: 'tone-danger' }

async function load() {
  loading.value = true
  try {
    const res = await api.notifications({ channel: channel.value, page: page.value })
    rows.value = res.data
    total.value = res.meta.total
    lastPage.value = res.meta.last_page
  } catch (e) {
    notifyError(e)
  } finally {
    loading.value = false
  }
}

async function runNow() {
  running.value = true
  try {
    const res = await api.runNotifications()
    notify(res.created ? `Создано сообщений: ${res.created}, документов в сводке: ${res.items}` : 'Новых сроков для уведомления нет: всё уже отправлено ранее', res.created ? 'success' : 'info')
    page.value = 1
    setTimeout(load, 800)
  } catch (e) {
    notifyError(e)
  } finally {
    running.value = false
  }
}

watch(channel, () => {
  page.value = 1
  load()
})
watch(page, load)
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div>
        <h1>Журнал уведомлений</h1>
        <p>Каждый день в 09:00 по Павлодару проверяются сроки. Уведомление уходит один раз на каждый порог (30, 14, 7 дней и просрочка): письмом и в Telegram. Без токена бота сообщение записывается сюда.</p>
      </div>
      <div class="page-actions">
        <a v-if="session.config?.mailpit_url" class="btn" :href="session.config.mailpit_url" target="_blank" rel="noopener"><Icon name="mail" />Почтовый ящик (Mailpit)</a>
        <button v-if="session.user?.can_edit" class="btn btn-primary" type="button" :disabled="running" @click="runNow"><span v-if="running" class="spinner" /><Icon v-else name="refresh" />Проверить сроки сейчас</button>
      </div>
    </div>

    <div class="segmented">
      <button type="button" :class="{ active: channel === '' }" @click="channel = ''">Все</button>
      <button v-for="(label, key) in channelLabels" :key="key" type="button" :class="{ active: channel === key }" @click="channel = key">{{ label }}</button>
    </div>

    <div class="card">
      <div class="card-head">
        <h2>Сообщений: {{ total }}</h2>
      </div>
      <div v-if="loading && !rows.length" class="loading-line">Загрузка...</div>
      <div v-else-if="!rows.length" class="empty">Уведомлений пока нет. Нажмите «Проверить сроки сейчас» или дождитесь ежедневной проверки.</div>
      <ul v-else class="log">
        <li v-for="n in rows" :key="n.id">
          <button type="button" class="log-head" @click="opened = opened === n.id ? null : n.id">
            <Icon :name="n.channel === 'email' ? 'mail' : n.channel === 'telegram' ? 'telegram' : 'list'" />
            <span class="log-main">
              <strong class="break">{{ n.subject }}</strong>
              <span class="cell-sub">{{ channelLabels[n.channel] }}<template v-if="n.recipient">: {{ n.recipient }}</template> · {{ formatDateTime(n.created_at) }}</span>
            </span>
            <span class="badge" :class="statusTones[n.status]">{{ statusLabels[n.status] }}</span>
          </button>
          <div v-if="opened === n.id" class="log-body">
            <div v-if="n.error" class="alert tone-warning small">{{ n.error }}</div>
            <pre class="code-box wrap">{{ n.body }}</pre>
          </div>
        </li>
      </ul>
      <div v-if="lastPage > 1" class="card-body row">
        <button class="btn btn-sm" type="button" :disabled="page <= 1" @click="page--">Назад</button>
        <span class="small muted">стр. {{ page }} из {{ lastPage }}</span>
        <button class="btn btn-sm" type="button" :disabled="page >= lastPage" @click="page++">Дальше</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.log {
  list-style: none;
  margin: 0;
  padding: 0;
}

.log li {
  border-bottom: 1px solid var(--c-border);
}

.log li:last-child {
  border-bottom: 0;
}

.log-head {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 18px;
  background: none;
  border: 0;
  text-align: left;
  cursor: pointer;
}

.log-head:hover {
  background: var(--c-surface-2);
}

.log-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.log-body {
  padding: 0 18px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

@media (max-width: 560px) {
  .log-head {
    flex-wrap: wrap;
    padding: 12px 14px;
  }
}
</style>
