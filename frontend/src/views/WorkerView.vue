<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api'
import type { DocumentType, WorkerCard, WorkerDocument } from '@/api'
import Avatar from '@/components/Avatar.vue'
import DocumentFormModal from '@/components/DocumentFormModal.vue'
import Icon from '@/components/Icon.vue'
import ReadyBadge from '@/components/ReadyBadge.vue'
import ScanViewer from '@/components/ScanViewer.vue'
import WorkerFormModal from '@/components/WorkerFormModal.vue'
import { session } from '@/stores/session'
import { notify, notifyError } from '@/stores/toast'
import { docText, docTone, formatDate, requestStatusLabels, requestStatusTones, roman } from '@/utils/format'

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))
const worker = ref<WorkerCard | null>(null)
const types = ref<DocumentType[]>([])
const editing = ref(false)
const docModal = ref<{ doc: WorkerDocument | null; preset: number | null } | null>(null)
const viewing = ref<WorkerDocument | null>(null)
const canEdit = computed(() => !!session.user?.can_edit)
const photoInput = ref<HTMLInputElement | null>(null)

async function load() {
  try {
    const [w, t] = await Promise.all([api.worker(id.value), types.value.length ? null : api.documentTypes()])
    worker.value = w
    if (t) types.value = t.data
  } catch (e) {
    notifyError(e)
  }
}

const missingBasic = computed(() => (worker.value?.readiness.items ?? []).filter((i) => i.state === 'missing'))

function onSavedDoc() {
  docModal.value = null
  notify('Документ сохранён')
  load()
}

async function removeDoc(d: WorkerDocument) {
  if (!confirm(`Удалить документ «${d.type_name}»${d.number ? ` № ${d.number}` : ''}?`)) return
  try {
    await api.deleteDocument(d.id)
    notify('Документ удалён')
    load()
  } catch (e) {
    notifyError(e)
  }
}

async function onPhoto(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file || !worker.value) return
  try {
    worker.value = await api.uploadPhoto(worker.value.id, file)
    notify('Фото обновлено')
  } catch (err) {
    notifyError(err)
  }
}

async function archive() {
  if (!worker.value) return
  const text = worker.value.is_active ? 'Убрать работника в архив? Он перестанет попадать в сводку и уведомления.' : 'Удалить работника окончательно вместе с документами?'
  if (!confirm(text)) return
  try {
    const res = await api.deleteWorker(worker.value.id)
    if (res.deleted) {
      notify('Работник удалён')
      router.push({ name: 'workers' })
    } else {
      notify('Работник в архиве')
      load()
    }
  } catch (e) {
    notifyError(e)
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <RouterLink class="btn btn-sm btn-ghost back" :to="{ name: 'workers' }"><Icon name="back" :size="16" />Все работники</RouterLink>
    <div v-if="!worker" class="card loading-line">Загрузка...</div>
    <template v-else>
      <div class="card card-body head-card">
        <div class="photo">
          <Avatar :worker-id="worker.id" :has-photo="worker.has_photo" :name="worker.full_name" :version="worker.photo_version" :size="96" />
          <button v-if="canEdit" class="btn btn-sm" type="button" @click="photoInput?.click()"><Icon name="camera" :size="15" />Фото</button>
          <input ref="photoInput" type="file" accept="image/*" hidden @change="onPhoto" />
        </div>
        <div class="head-info">
          <div class="row-between">
            <h1 class="break">{{ worker.full_name }}</h1>
            <div class="page-actions">
              <button v-if="canEdit" class="btn" type="button" @click="editing = true"><Icon name="edit" />Изменить</button>
              <button v-if="canEdit" class="btn btn-danger" type="button" @click="archive">
                <Icon :name="worker.is_active ? 'archive' : 'trash'" />{{ worker.is_active ? 'В архив' : 'Удалить' }}
              </button>
            </div>
          </div>
          <p class="text-2">{{ worker.position }}<template v-if="worker.grade">, {{ worker.grade }} разряд</template><template v-if="worker.site">, {{ worker.site }}</template></p>
          <dl class="kv">
            <dt>ИИН</dt>
            <dd class="mono">{{ worker.iin }}</dd>
            <dt>Дата рождения</dt>
            <dd>{{ formatDate(worker.birth_date) }}</dd>
            <dt v-if="worker.phone">Телефон</dt>
            <dd v-if="worker.phone">{{ worker.phone }}</dd>
            <dt v-if="worker.hired_at">Принят</dt>
            <dd v-if="worker.hired_at">{{ formatDate(worker.hired_at) }}</dd>
          </dl>
          <div class="row">
            <span class="muted small">Сегодня:</span>
            <ReadyBadge :status="worker.readiness.status" :threshold="worker.readiness.threshold" :days-left="worker.readiness.days_left" />
            <span class="muted small">Завтра:</span>
            <ReadyBadge :status="worker.readiness_tomorrow.status" :threshold="worker.readiness_tomorrow.threshold" :days-left="worker.readiness_tomorrow.days_left" />
            <span v-if="!worker.is_active" class="badge tone-muted">в архиве</span>
          </div>
        </div>
      </div>

      <div v-if="worker.readiness.problems.length || worker.readiness.warnings.length" class="alert" :class="worker.readiness.problems.length ? 'tone-danger' : 'tone-warning'">
        <Icon name="alert" />
        <div>
          <strong>{{ worker.readiness.problems.length ? 'Чего не хватает по базовым документам' : 'Скоро истекает' }}</strong>
          <ul class="plain-list">
            <li v-for="p in worker.readiness.problems" :key="p">{{ p }}</li>
            <li v-for="p in worker.readiness.warnings" :key="p">{{ p }}</li>
          </ul>
          <div v-if="canEdit && missingBasic.length" class="row add-missing">
            <button v-for="m in missingBasic" :key="m.document_type_id" class="btn btn-sm" type="button" @click="docModal = { doc: null, preset: m.document_type_id }">
              <Icon name="plus" :size="14" />{{ m.short_name }}
            </button>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <h2>Документы ({{ worker.documents.length }})</h2>
          <button v-if="canEdit" class="btn btn-primary btn-sm" type="button" @click="docModal = { doc: null, preset: null }"><Icon name="plus" :size="15" />Добавить документ</button>
        </div>
        <div v-if="!worker.documents.length" class="empty">Документов пока нет</div>
        <template v-else>
          <div class="table-wrap desktop-table">
            <table class="table">
              <thead>
                <tr>
                  <th>Документ</th>
                  <th>Номер</th>
                  <th>Выдан</th>
                  <th>Действует до</th>
                  <th>Статус</th>
                  <th>Скан</th>
                  <th v-if="canEdit" class="actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in worker.documents" :key="d.id">
                  <td>
                    <div class="cell-title">{{ d.type_name }}<template v-if="d.group_level">, группа {{ roman(d.group_level) }}</template></div>
                    <div class="cell-sub">{{ d.issuer }}<template v-if="d.notes"> · {{ d.notes }}</template></div>
                  </td>
                  <td class="nowrap">{{ d.number }}</td>
                  <td class="nowrap">{{ formatDate(d.issued_at) }}</td>
                  <td class="nowrap">{{ d.valid_until ? formatDate(d.valid_until) : 'бессрочно' }}</td>
                  <td><span class="badge" :class="docTone(d.state, d.threshold)">{{ docText(d.state, d.days_left) }}</span></td>
                  <td>
                    <button v-if="d.has_scan" class="btn btn-sm btn-ghost" type="button" @click="viewing = d"><Icon name="eye" :size="15" />Открыть</button>
                    <span v-else class="muted small">нет</span>
                  </td>
                  <td v-if="canEdit" class="actions">
                    <button class="btn btn-sm btn-ghost btn-icon" type="button" title="Изменить" aria-label="Изменить" @click="docModal = { doc: d, preset: null }"><Icon name="edit" :size="16" /></button>
                    <button class="btn btn-sm btn-ghost btn-icon" type="button" title="Удалить" aria-label="Удалить" @click="removeDoc(d)"><Icon name="trash" :size="16" /></button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="mobile-cards">
            <div v-for="d in worker.documents" :key="d.id" class="m-card">
              <div class="cell-title">{{ d.type_name }}<template v-if="d.group_level">, группа {{ roman(d.group_level) }}</template></div>
              <div class="m-meta">
                <span v-if="d.number">№ {{ d.number }}</span>
                <span>до {{ d.valid_until ? formatDate(d.valid_until) : 'бессрочно' }}</span>
              </div>
              <div class="row">
                <span class="badge" :class="docTone(d.state, d.threshold)">{{ docText(d.state, d.days_left) }}</span>
                <button v-if="d.has_scan" class="btn btn-sm" type="button" @click="viewing = d"><Icon name="eye" :size="15" />Скан</button>
                <button v-if="canEdit" class="btn btn-sm" type="button" @click="docModal = { doc: d, preset: null }"><Icon name="edit" :size="15" />Изменить</button>
              </div>
            </div>
          </div>
        </template>
      </div>

      <div class="card">
        <div class="card-head"><h2>Заявки на допуск</h2></div>
        <div v-if="!worker.requests.length" class="empty">Работник ещё не включён в заявки</div>
        <ul v-else class="req-list">
          <li v-for="r in worker.requests" :key="r.id">
            <RouterLink :to="{ name: 'request', params: { id: r.id } }">{{ r.number }}</RouterLink>
            <span>{{ r.customer }}, {{ formatDate(r.start_date) }} - {{ formatDate(r.end_date) }}</span>
            <span class="badge" :class="requestStatusTones[r.status]">{{ requestStatusLabels[r.status] }}</span>
          </li>
        </ul>
      </div>
    </template>

    <WorkerFormModal v-if="editing && worker" :worker="worker" @close="editing = false" @saved="(w) => { editing = false; worker = w; notify('Сохранено') }" />
    <DocumentFormModal
      v-if="docModal && worker"
      :worker-id="worker.id"
      :types="types"
      :doc="docModal.doc"
      :preset-type-id="docModal.preset"
      @close="docModal = null"
      @saved="onSavedDoc"
    />
    <ScanViewer v-if="viewing" :doc-id="viewing.id" :title="`${viewing.type_name}${viewing.number ? `, № ${viewing.number}` : ''}`" :mime="viewing.scan_mime" @close="viewing = null" />
  </div>
</template>

<style scoped>
.back {
  align-self: flex-start;
}

.head-card {
  display: flex;
  gap: 20px;
  align-items: flex-start;
}

.photo {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  flex: none;
}

.head-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.plain-list {
  margin: 6px 0 0;
  padding-left: 18px;
}

.add-missing {
  margin-top: 8px;
}

.req-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.req-list li {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 12px;
  align-items: center;
  padding: 10px 18px;
  border-bottom: 1px solid var(--c-border);
}

.req-list li:last-child {
  border-bottom: 0;
}

@media (max-width: 600px) {
  .head-card {
    flex-direction: column;
    align-items: stretch;
  }

  .photo {
    flex-direction: row;
  }
}
</style>
