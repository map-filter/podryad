<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/api'
import type { ReadinessItem, RequestFull, RequestWorker } from '@/api'
import Icon from '@/components/Icon.vue'
import ReadinessMatrix from '@/components/ReadinessMatrix.vue'
import ScanViewer from '@/components/ScanViewer.vue'
import { notify, notifyError } from '@/stores/toast'
import { download } from '@/utils/download'
import { errorMessage, fieldErrors } from '@/utils/errors'
import { formatDate, formatDateTime, readyLabels, readyTones, requestStatusLabels, requestStatusTones } from '@/utils/format'

const route = useRoute()
const id = computed(() => Number(route.params.id))
const r = ref<RequestFull | null>(null)
const scan = ref<{ item: ReadinessItem; worker: RequestWorker } | null>(null)
const comment = ref('')
const remarks = reactive<Record<number, { on: boolean; comment: string }>>({})
const busy = ref(false)
const error = ref('')

async function load() {
  try {
    r.value = await api.customerRequest(id.value)
    for (const w of r.value.workers) remarks[w.id] = { on: w.customer_decision === 'remark', comment: w.customer_comment ?? '' }
  } catch (e) {
    notifyError(e)
  }
}

async function decide(decision: 'approved' | 'remarks') {
  if (!r.value) return
  busy.value = true
  error.value = ''
  try {
    const workers = r.value.workers.map((w) => ({
      id: w.id,
      decision: remarks[w.id]?.on ? 'remark' : decision === 'approved' ? 'ok' : null,
      comment: remarks[w.id]?.on ? remarks[w.id].comment : null,
    }))
    r.value = await api.customerDecision(id.value, { decision, comment: comment.value, workers })
    notify(decision === 'approved' ? 'Работники допущены' : 'Заявка возвращена с замечаниями')
  } catch (e) {
    const f = fieldErrors(e)
    error.value = f.comment ?? errorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <RouterLink class="btn btn-sm btn-ghost back" :to="{ name: 'customer-requests' }"><Icon name="back" :size="16" />Все заявки</RouterLink>
    <div v-if="!r" class="card loading-line">Загрузка...</div>
    <template v-else>
      <div class="page-head">
        <div>
          <div class="row">
            <h1>Заявка {{ r.number }}</h1>
            <span class="badge" :class="requestStatusTones[r.status]">{{ r.status === 'submitted' ? 'Ждёт проверки' : requestStatusLabels[r.status] }}</span>
          </div>
          <p>{{ r.organization?.name }}, БИН {{ r.organization?.bin }}</p>
        </div>
        <div class="page-actions">
          <button class="btn" type="button" @click="download(`/customer/requests/${r.id}/package`, `Пакет_допуска_${r.number}.zip`)"><Icon name="download" />Пакет допуска ZIP</button>
        </div>
      </div>

      <div class="grid grid-2">
        <div class="card card-body">
          <dl class="kv">
            <dt>Объект</dt>
            <dd>{{ r.facility?.name ?? 'не указан' }}</dd>
            <dt>Вид работ</dt>
            <dd>{{ r.work_type }}<template v-if="r.work_description">: {{ r.work_description }}</template></dd>
            <dt>Период</dt>
            <dd>{{ formatDate(r.start_date) }} - {{ formatDate(r.end_date) }}</dd>
            <dt>Профиль</dt>
            <dd>{{ r.profile?.name }}</dd>
          </dl>
        </div>
        <div class="card card-body stack">
          <div class="row">
            <span class="badge" :class="readyTones[r.readiness.status]">{{ readyLabels[r.readiness.status] }}</span>
            <span class="small text-2">проверено на {{ formatDate(r.readiness.on) }}: готовы {{ r.readiness.counts.ready }}, истекает {{ r.readiness.counts.expiring }}, не готовы {{ r.readiness.counts.not_ready }}</span>
          </div>
          <div class="row">
            <Icon name="sign" />
            <span v-if="r.signed_at && r.signature_valid" class="small">Подписано ЭЦП: {{ r.signer_subject }}, {{ formatDateTime(r.signed_at) }}</span>
            <span v-else-if="r.signed_at" class="small danger">Данные изменились после подписи</span>
            <span v-else class="small muted">Без подписи</span>
          </div>
          <div v-if="r.status !== 'submitted'" class="alert" :class="r.status === 'approved' ? 'tone-success' : 'tone-danger'">
            <Icon :name="r.status === 'approved' ? 'check' : 'alert'" />
            <div>
              <strong>{{ r.status === 'approved' ? 'Допущены' : 'Возвращено с замечаниями' }}</strong>, {{ r.reviewer }}, {{ formatDateTime(r.reviewed_at) }}
              <p v-if="r.review_comment">{{ r.review_comment }}</p>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h2>Работники и документы ({{ r.workers.length }})</h2></div>
        <ReadinessMatrix :workers="r.workers" :items="r.profile_items" :show-decision="r.status !== 'submitted'" @scan="(item, worker) => (scan = { item, worker })" />
      </div>

      <div v-if="r.status === 'submitted'" class="card">
        <div class="card-head"><h2>Решение по заявке</h2></div>
        <div class="card-body stack">
          <div class="field-label">Замечания по отдельным работникам</div>
          <div class="remarks">
            <div v-for="w in r.workers" :key="w.id" class="remark-row">
              <label class="check"><input v-model="remarks[w.id].on" type="checkbox" /> {{ w.full_name }}</label>
              <input v-if="remarks[w.id].on" v-model="remarks[w.id].comment" class="input" placeholder="Что не так с документами" :aria-label="`Замечание: ${w.full_name}`" />
            </div>
          </div>
          <div class="field">
            <label for="c-comment">Комментарий подрядчику</label>
            <textarea id="c-comment" v-model="comment" class="textarea" rows="3" placeholder="Обязателен при замечаниях" />
          </div>
          <div v-if="error" class="alert tone-danger">{{ error }}</div>
          <div class="row">
            <button class="btn btn-primary" type="button" :disabled="busy" @click="decide('approved')"><Icon name="check" />Допустить</button>
            <button class="btn btn-danger" type="button" :disabled="busy" @click="decide('remarks')"><Icon name="alert" />Вернуть с замечаниями</button>
          </div>
        </div>
      </div>
    </template>
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

.danger {
  color: var(--c-danger);
}

.remarks {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.remark-row {
  display: grid;
  grid-template-columns: minmax(0, 280px) minmax(0, 1fr);
  gap: 8px 12px;
  align-items: center;
}

@media (max-width: 640px) {
  .remark-row {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
