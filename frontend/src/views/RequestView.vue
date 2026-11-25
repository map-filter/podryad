<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api'
import type { ReadinessItem, RequestFull, RequestWorker, SignData } from '@/api'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import ReadinessMatrix from '@/components/ReadinessMatrix.vue'
import RequestFormModal from '@/components/RequestFormModal.vue'
import ScanViewer from '@/components/ScanViewer.vue'
import WorkerPickerModal from '@/components/WorkerPickerModal.vue'
import { getSigner } from '@/services/signer'
import { session } from '@/stores/session'
import { notify, notifyError } from '@/stores/toast'
import { download } from '@/utils/download'
import { errorMessage } from '@/utils/errors'
import { formatDate, formatDateTime, readyLabels, readyTones, requestStatusLabels, requestStatusTones } from '@/utils/format'

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))
const r = ref<RequestFull | null>(null)
const editing = ref(false)
const picking = ref(false)
const signPreview = ref<SignData | null>(null)
const signing = ref(false)
const signError = ref('')
const submitting = ref(false)
const scan = ref<{ item: ReadinessItem; worker: RequestWorker } | null>(null)

const editable = computed(() => !!r.value && (r.value.status === 'draft' || r.value.status === 'remarks'))
const canSign = computed(() => !!session.user?.can_sign)
const signMode = computed(() => session.config?.sign_mode ?? 'ncalayer')
const notReady = computed(() => r.value?.readiness.counts.not_ready ?? 0)

const steps = computed(() => {
  const v = r.value
  if (!v) return []
  return [
    { label: 'Состав', done: v.workers.length > 0 },
    { label: 'Все готовы', done: v.workers.length > 0 && notReady.value === 0 },
    { label: 'Подписано ЭЦП', done: v.signature_valid },
    { label: 'Подано заказчику', done: v.status !== 'draft' && !!v.submitted_at },
    { label: v.status === 'remarks' ? 'Замечания' : 'Допущены', done: v.status === 'approved', bad: v.status === 'remarks' },
  ]
})

async function load() {
  try {
    r.value = await api.request(id.value)
    if (route.query.pick === '1' && editable.value && !r.value.workers.length) picking.value = true
  } catch (e) {
    notifyError(e)
  }
}

async function addWorkers(ids: number[]) {
  picking.value = false
  try {
    r.value = await api.requestWorkers(id.value, ids, [])
    notify(`Добавлено: ${ids.length}`)
  } catch (e) {
    notifyError(e)
  }
}

async function removeWorker(workerId: number) {
  try {
    r.value = await api.requestWorkers(id.value, [], [workerId])
  } catch (e) {
    notifyError(e)
  }
}

async function removeNotReady() {
  if (!r.value) return
  const ids = r.value.workers.filter((w) => w.readiness.status === 'not_ready').map((w) => w.id)
  try {
    r.value = await api.requestWorkers(id.value, [], ids)
    notify(`Убрано из заявки: ${ids.length}`)
  } catch (e) {
    notifyError(e)
  }
}

async function openSign() {
  signError.value = ''
  try {
    signPreview.value = await api.signData(id.value)
  } catch (e) {
    notifyError(e)
  }
}

async function doSign() {
  if (!signPreview.value) return
  signing.value = true
  signError.value = ''
  try {
    const signer = getSigner(signMode.value)
    const signature = await signer.signCms(signPreview.value.data_base64, { attached: true })
    r.value = await api.sign(id.value, signature)
    signPreview.value = null
    notify('Список подписан')
  } catch (e) {
    signError.value = errorMessage(e)
  } finally {
    signing.value = false
  }
}

async function submit() {
  submitting.value = true
  try {
    r.value = await api.submit(id.value)
    notify('Заявка подана заказчику')
  } catch (e) {
    notifyError(e)
  } finally {
    submitting.value = false
  }
}

async function remove() {
  if (!confirm('Удалить черновик заявки?')) return
  try {
    await api.deleteRequest(id.value)
    notify('Черновик удалён')
    router.push({ name: 'requests' })
  } catch (e) {
    notifyError(e)
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <RouterLink class="btn btn-sm btn-ghost back" :to="{ name: 'requests' }"><Icon name="back" :size="16" />Все заявки</RouterLink>
    <div v-if="!r" class="card loading-line">Загрузка...</div>
    <template v-else>
      <div class="page-head">
        <div>
          <div class="row">
            <h1>Заявка {{ r.number }}</h1>
            <span class="badge" :class="requestStatusTones[r.status]">{{ requestStatusLabels[r.status] }}</span>
          </div>
          <p>{{ r.customer?.name }}<template v-if="r.facility">, {{ r.facility.name }}</template></p>
        </div>
        <div class="page-actions">
          <button v-if="editable" class="btn" type="button" @click="editing = true"><Icon name="edit" />Изменить</button>
          <button v-if="r.status === 'draft'" class="btn btn-danger" type="button" @click="remove"><Icon name="trash" />Удалить</button>
        </div>
      </div>

      <ol class="steps card card-body">
        <li v-for="(s, i) in steps" :key="s.label" :class="{ done: s.done, bad: s.bad }">
          <span class="step-n">{{ s.done ? '✓' : s.bad ? '!' : i + 1 }}</span>
          <span>{{ s.label }}</span>
        </li>
      </ol>

      <div v-if="r.status === 'remarks' || (r.status === 'approved' && r.review_comment)" class="alert" :class="r.status === 'remarks' ? 'tone-danger' : 'tone-success'">
        <Icon :name="r.status === 'remarks' ? 'alert' : 'check'" />
        <div>
          <strong>{{ r.status === 'remarks' ? 'Заказчик вернул заявку с замечаниями' : 'Заказчик допустил работников' }}</strong>
          <span v-if="r.reviewer">, {{ r.reviewer }}, {{ formatDateTime(r.reviewed_at) }}</span>
          <p v-if="r.review_comment">{{ r.review_comment }}</p>
          <p v-if="r.status === 'remarks'" class="small">Исправьте документы или состав, подпишите список заново и подайте повторно.</p>
        </div>
      </div>

      <div class="grid grid-2">
        <div class="card card-body">
          <dl class="kv">
            <dt>Вид работ</dt>
            <dd>{{ r.work_type }}</dd>
            <dt v-if="r.work_description">Описание</dt>
            <dd v-if="r.work_description">{{ r.work_description }}</dd>
            <dt>Период</dt>
            <dd>{{ formatDate(r.start_date) }} - {{ formatDate(r.end_date) }}</dd>
            <dt>Профиль</dt>
            <dd>{{ r.profile?.name }}</dd>
            <dt>Проверка на</dt>
            <dd>{{ formatDate(r.readiness.on) }}, с учётом окончания работ</dd>
            <dt>Подпись</dt>
            <dd>
              <template v-if="r.signed_at">
                <span class="badge" :class="r.signature_valid ? 'tone-success' : 'tone-warning'">{{ r.signature_valid ? 'действительна' : 'список изменён после подписи' }}</span>
                <div class="small text-2">{{ r.signer_subject }}, {{ formatDateTime(r.signed_at) }}</div>
              </template>
              <span v-else class="muted">не подписан</span>
            </dd>
          </dl>
        </div>
        <div class="card card-body stack">
          <div class="row">
            <span class="badge" :class="readyTones[r.readiness.status]">{{ readyLabels[r.readiness.status] }}</span>
            <span class="small text-2">готовы {{ r.readiness.counts.ready }}, срок истекает {{ r.readiness.counts.expiring }}, не готовы {{ r.readiness.counts.not_ready }}</span>
          </div>
          <div class="actions-grid">
            <button
              v-if="editable && canSign"
              class="btn btn-primary"
              type="button"
              :disabled="!r.workers.length || notReady > 0 || r.signature_valid"
              :title="notReady > 0 ? 'Сначала уберите неготовых работников или обновите их документы' : ''"
              @click="openSign"
            >
              <Icon name="sign" />{{ r.signature_valid ? 'Список подписан' : 'Подписать ЭЦП' }}
            </button>
            <button v-if="editable && canSign" class="btn btn-primary" type="button" :disabled="!r.signature_valid || submitting" @click="submit">
              <Icon name="send" />Подать заказчику
            </button>
            <button class="btn" type="button" @click="download(`/requests/${r.id}/package`, `Пакет_допуска_${r.number}.zip`)"><Icon name="download" />Пакет допуска ZIP</button>
            <button class="btn" type="button" @click="download(`/requests/${r.id}/pass-list`, `Пропуск_${r.number}.xlsx`)"><Icon name="download" />Список на пропуск xlsx</button>
            <button class="btn" type="button" @click="download(`/requests/${r.id}/summary.pdf`, `Список_${r.number}.pdf`)"><Icon name="file" />Список PDF</button>
          </div>
          <p v-if="editable && !canSign" class="small muted">Подписать и подать заявку могут руководитель и специалист по ОТ.</p>
          <p v-if="notReady > 0 && editable" class="small text-2">
            Не готовы: {{ notReady }}. Подпись станет доступна, когда в списке останутся только готовые.
            <button class="btn btn-sm btn-ghost" type="button" @click="removeNotReady">Убрать неготовых</button>
          </p>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <h2>Работники и документы по профилю ({{ r.workers.length }})</h2>
          <button v-if="editable" class="btn btn-sm btn-primary" type="button" @click="picking = true"><Icon name="plus" :size="15" />Добавить работников</button>
        </div>
        <div v-if="!r.workers.length" class="empty">
          В заявке пока нет работников
          <div><button v-if="editable" class="btn btn-primary" type="button" @click="picking = true">Выбрать работников</button></div>
        </div>
        <ReadinessMatrix
          v-else
          :workers="r.workers"
          :items="r.profile_items"
          :removable="editable"
          link-workers
          :show-decision="r.status !== 'draft'"
          @remove="removeWorker"
          @scan="(item, worker) => (scan = { item, worker })"
        />
        <div class="card-body legend small muted">
          Дата в клетке: до какого числа действует документ. Зелёный: в порядке, жёлтый: истекает в пределах порога или до окончания работ, красный: нет, просрочен или мала группа. Клетка с подчёркиванием открывает скан.
        </div>
      </div>
    </template>

    <RequestFormModal v-if="editing && r" :request="r" @close="editing = false" @saved="(v) => { editing = false; r = v; notify('Сохранено') }" />
    <WorkerPickerModal
      v-if="picking && r && r.profile"
      :profile-id="r.profile.id"
      :date="r.readiness.on"
      :exclude="r.workers.map((w) => w.id)"
      @close="picking = false"
      @pick="addWorkers"
    />
    <Modal v-if="signPreview" title="Подпись списка ЭЦП" :width="760" @close="signPreview = null">
      <div class="stack">
        <div v-if="signMode === 'emulate'" class="alert tone-warning">
          <Icon name="info" />
          <div>Включён режим эмуляции: подпись создаётся сервером без настоящего ключа. Для боевой работы поставьте SIGN_MODE=ncalayer, тогда подпись делает NCALayer на этом компьютере, ключ не покидает машину.</div>
        </div>
        <div v-else class="alert tone-info">
          <Icon name="info" />
          <div>Подпись выполнит NCALayer (wss://127.0.0.1:13579). Выберите ключ ЭЦП и введите пароль в окне NCALayer.</div>
        </div>
        <div class="field">
          <span class="field-label">Подписываемые данные (SHA-256 {{ signPreview.hash.slice(0, 16) }}...)</span>
          <pre class="code-box sign-data">{{ signPreview.data }}</pre>
        </div>
        <div v-if="signError" class="alert tone-danger">{{ signError }}</div>
      </div>
      <template #footer>
        <button class="btn" type="button" @click="signPreview = null">Отмена</button>
        <button class="btn btn-primary" type="button" :disabled="signing" @click="doSign"><span v-if="signing" class="spinner" /><Icon v-else name="sign" />Подписать</button>
      </template>
    </Modal>
    <ScanViewer
      v-if="scan && scan.item.document_id"
      :doc-id="scan.item.document_id"
      :title="`${scan.worker.full_name}: ${scan.item.name}`"
      :mime="scan.item.scan_mime ?? null"
      @close="scan = null"
    />
  </div>
</template>

<style scoped>
.back {
  align-self: flex-start;
}

.steps {
  list-style: none;
  margin: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 8px 22px;
  padding: 12px 18px;
}

.steps li {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--c-muted);
  font-size: 14px;
  font-weight: 550;
}

.step-n {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--c-neutral-soft);
  font-size: 12.5px;
  flex: none;
}

.steps li.done {
  color: var(--c-success);
}

.steps li.done .step-n {
  background: var(--c-success);
  color: #fff;
}

.steps li.bad {
  color: var(--c-danger);
}

.steps li.bad .step-n {
  background: var(--c-danger);
  color: #fff;
}

.actions-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.sign-data {
  max-height: 40vh;
  overflow: auto;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.legend {
  border-top: 1px solid var(--c-border);
}

@media (max-width: 560px) {
  .actions-grid .btn {
    flex: 1 1 100%;
  }
}
</style>
