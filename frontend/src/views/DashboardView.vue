<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api'
import type { Dashboard, ReadyStatus } from '@/api'
import Avatar from '@/components/Avatar.vue'
import Icon from '@/components/Icon.vue'
import ReadyBadge from '@/components/ReadyBadge.vue'
import { notifyError } from '@/stores/toast'
import { docText, docTone, formatDate, requestStatusLabels, requestStatusTones, readyTones } from '@/utils/format'

const data = ref<Dashboard | null>(null)
const loading = ref(true)
const profileId = ref<number | null>(null)
const tab = ref<ReadyStatus>('ready')
const showAllExpiring = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = await api.dashboard({ profile_id: profileId.value })
  } catch (e) {
    notifyError(e)
  } finally {
    loading.value = false
  }
}

function selectProfile(id: number | null) {
  profileId.value = id
  load()
}

const list = computed(() => (data.value?.workers ?? []).filter((w) => w.readiness.status === tab.value))
const expiring = computed(() => (showAllExpiring.value ? data.value?.expiring ?? [] : (data.value?.expiring ?? []).slice(0, 10)))
const profileName = computed(() => data.value?.profiles.find((p) => p.id === profileId.value)?.name ?? 'базовые документы')

const tabs: { key: ReadyStatus; label: string }[] = [
  { key: 'ready', label: 'Могут выйти' },
  { key: 'expiring', label: 'Выйдут, но срок истекает' },
  { key: 'not_ready', label: 'Не могут выйти' },
]

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div>
        <h1>Кто может выйти завтра</h1>
        <p v-if="data">Проверка на {{ formatDate(data.date) }} по профилю: {{ profileName }}. Пороги предупреждений: {{ data.thresholds.join(', ') }} дней.</p>
      </div>
      <div class="page-actions">
        <RouterLink class="btn" :to="{ name: 'workers' }"><Icon name="users" />Работники</RouterLink>
        <RouterLink class="btn btn-primary" :to="{ name: 'requests' }"><Icon name="clipboard" />Заявки на допуск</RouterLink>
      </div>
    </div>

    <div v-if="loading && !data" class="card loading-line">Загрузка...</div>

    <template v-if="data">
      <div class="stat-tiles">
        <div class="card stat">
          <span class="stat-label"><span class="dot tone-success" />Готовы к выходу</span>
          <span class="stat-value">{{ data.totals.ready }}</span>
          <span class="stat-sub">из {{ data.totals.workers }} работников</span>
        </div>
        <div class="card stat">
          <span class="stat-label"><span class="dot tone-warning" />Готовы, срок истекает</span>
          <span class="stat-value">{{ data.totals.expiring }}</span>
          <span class="stat-sub">в пределах {{ data.thresholds[0] }} дней</span>
        </div>
        <div class="card stat">
          <span class="stat-label"><span class="dot tone-danger" />Не готовы</span>
          <span class="stat-value">{{ data.totals.not_ready }}</span>
          <span class="stat-sub">нет документа или просрочен</span>
        </div>
        <RouterLink class="card stat" :to="{ name: 'workers', query: { status: 'not_ready' } }">
          <span class="stat-label"><Icon name="alert" :size="15" />Документы</span>
          <span class="stat-value">{{ data.totals.docs_expired }} / {{ data.totals.docs_expiring }}</span>
          <span class="stat-sub">просрочено / истекает за {{ data.thresholds[0] }} дней</span>
        </RouterLink>
      </div>

      <div class="card">
        <div class="card-head">
          <h2>Готовность по профилям требований</h2>
          <span class="muted small">Нажмите на профиль, чтобы увидеть, кто проходит под его набор документов</span>
        </div>
        <div class="card-body stack">
          <div class="chips">
            <button type="button" class="chip" :class="{ active: profileId === null }" @click="selectProfile(null)">
              <span>Базовые документы</span>
            </button>
            <button
              v-for="p in data.profiles"
              :key="p.id"
              type="button"
              class="chip"
              :class="{ active: profileId === p.id }"
              :title="p.name"
              @click="selectProfile(p.id)"
            >
              <span>{{ p.name }}</span>
              <b>{{ p.counts.ready + p.counts.expiring }}/{{ p.counts.ready + p.counts.expiring + p.counts.not_ready }}</b>
            </button>
          </div>
          <div class="segmented" role="tablist">
            <button v-for="t in tabs" :key="t.key" type="button" :class="{ active: tab === t.key }" @click="tab = t.key">
              {{ t.label }} ({{ data.totals[t.key] }})
            </button>
          </div>
          <div v-if="!list.length" class="empty">Таких работников нет</div>
          <ul v-else class="people">
            <li v-for="w in list" :key="w.id">
              <RouterLink :to="{ name: 'worker', params: { id: w.id } }" class="person">
                <Avatar :worker-id="w.id" :has-photo="w.has_photo" :name="w.full_name" :version="w.photo_version" :size="34" />
                <div>
                  <div class="cell-title">{{ w.full_name }}</div>
                  <div class="cell-sub">{{ w.position }}</div>
                </div>
              </RouterLink>
              <div class="reasons">
                <ReadyBadge :status="w.readiness.status" :threshold="w.readiness.threshold" :days-left="w.readiness.days_left" />
                <span v-for="p in w.readiness.problems" :key="p" class="reason danger">{{ p }}</span>
                <span v-for="p in w.readiness.warnings" :key="p" class="reason">{{ p }}</span>
              </div>
            </li>
          </ul>
        </div>
      </div>

      <div class="grid grid-2">
        <div class="card">
          <div class="card-head">
            <h2>Что истекает в ближайшие {{ data.thresholds[0] }} дней</h2>
            <span class="badge tone-warning">{{ data.expiring.length }}</span>
          </div>
          <div v-if="!data.expiring.length" class="empty">Ничего не истекает</div>
          <ul v-else class="doc-list">
            <li v-for="d in expiring" :key="d.id">
              <span class="dot" :class="docTone(d.state, d.threshold)" />
              <div class="doc-main">
                <RouterLink :to="{ name: 'worker', params: { id: d.worker.id } }" class="cell-title">{{ d.worker.full_name }}</RouterLink>
                <div class="cell-sub">{{ d.type_name }}<template v-if="d.number">, № {{ d.number }}</template></div>
              </div>
              <div class="doc-when">
                <span class="badge" :class="docTone(d.state, d.threshold)">{{ docText(d.state, d.days_left) }}</span>
                <span class="cell-sub">до {{ formatDate(d.valid_until) }}</span>
              </div>
            </li>
          </ul>
          <div v-if="data.expiring.length > 10" class="card-body more">
            <button class="btn btn-sm btn-ghost" type="button" @click="showAllExpiring = !showAllExpiring">
              {{ showAllExpiring ? 'Свернуть' : `Показать все (${data.expiring.length})` }}
            </button>
          </div>
        </div>

        <div class="stack">
          <div class="card">
            <div class="card-head">
              <h2>Просрочено</h2>
              <span class="badge tone-danger">{{ data.expired.length }}</span>
            </div>
            <div v-if="!data.expired.length" class="empty">Просроченных документов нет</div>
            <ul v-else class="doc-list">
              <li v-for="d in data.expired" :key="d.id">
                <span class="dot tone-danger" />
                <div class="doc-main">
                  <RouterLink :to="{ name: 'worker', params: { id: d.worker.id } }" class="cell-title">{{ d.worker.full_name }}</RouterLink>
                  <div class="cell-sub">{{ d.type_name }}</div>
                </div>
                <div class="doc-when">
                  <span class="badge tone-danger">{{ docText(d.state, d.days_left) }}</span>
                  <span class="cell-sub">до {{ formatDate(d.valid_until) }}</span>
                </div>
              </li>
            </ul>
          </div>

          <div class="card">
            <div class="card-head">
              <h2>Ближайшие заявки</h2>
              <RouterLink class="btn btn-sm btn-ghost" :to="{ name: 'requests' }">Все заявки</RouterLink>
            </div>
            <div v-if="!data.requests.length" class="empty">Заявок нет</div>
            <ul v-else class="doc-list">
              <li v-for="r in data.requests" :key="r.id">
                <span class="dot" :class="r.readiness ? readyTones[r.readiness.status] : 'tone-muted'" />
                <div class="doc-main">
                  <RouterLink :to="{ name: 'request', params: { id: r.id } }" class="cell-title">{{ r.number }}, {{ r.customer?.short_name }}</RouterLink>
                  <div class="cell-sub">{{ r.work_type }}, {{ formatDate(r.start_date) }} - {{ formatDate(r.end_date) }}, {{ r.workers_count }} чел.</div>
                </div>
                <div class="doc-when">
                  <span class="badge" :class="requestStatusTones[r.status]">{{ requestStatusLabels[r.status] }}</span>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.people {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px 18px;
}

.people li {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 6px 10px;
  padding: 8px 0;
  border-bottom: 1px solid var(--c-border);
  min-width: 0;
}

.people .person {
  text-decoration: none;
  color: inherit;
}

.people .cell-title {
  min-width: 0;
}

.reasons {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 8px;
  align-items: center;
  justify-content: flex-end;
  min-width: 0;
}

.reason {
  font-size: 12.5px;
  color: var(--c-warning);
}

.reason.danger {
  color: var(--c-danger);
}

.doc-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.doc-list li {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 18px;
  border-bottom: 1px solid var(--c-border);
  min-width: 0;
}

.doc-list li:last-child {
  border-bottom: 0;
}

.doc-main {
  flex: 1 1 auto;
  min-width: 0;
}

.doc-main .cell-title {
  display: block;
  min-width: 0;
  text-decoration: none;
  color: var(--c-text);
}

.doc-when {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
  flex: none;
}

.more {
  padding-top: 8px;
  padding-bottom: 10px;
}

@media (max-width: 900px) {
  .people {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (max-width: 480px) {
  .doc-list li {
    flex-wrap: wrap;
    padding: 10px 14px;
  }

  .doc-when {
    align-items: flex-start;
    flex-direction: row;
    gap: 8px;
    padding-left: 20px;
    flex-wrap: wrap;
  }

  .reasons {
    padding-left: 0;
  }
}
</style>
