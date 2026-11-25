<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api'
import type { RequestBrief, RequestFull } from '@/api'
import Icon from '@/components/Icon.vue'
import RequestFormModal from '@/components/RequestFormModal.vue'
import { notifyError } from '@/stores/toast'
import { formatDate, readyLabels, readyTones, requestStatusLabels, requestStatusTones } from '@/utils/format'

const router = useRouter()
const rows = ref<RequestBrief[]>([])
const loading = ref(true)
const status = ref('')
const creating = ref(false)

async function load() {
  loading.value = true
  try {
    rows.value = (await api.requests({ status: status.value })).data
  } catch (e) {
    notifyError(e)
  } finally {
    loading.value = false
  }
}

watch(status, load)
onMounted(load)

function onCreated(r: RequestFull) {
  creating.value = false
  router.push({ name: 'request', params: { id: r.id }, query: { pick: '1' } })
}
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div>
        <h1>Заявки на допуск</h1>
        <p>Заявка собирает список работников на объект заказчика и проверяет их документы по профилю требований на даты работ.</p>
      </div>
      <div class="page-actions">
        <button class="btn btn-primary" type="button" @click="creating = true"><Icon name="plus" />Новая заявка</button>
      </div>
    </div>

    <div class="segmented">
      <button type="button" :class="{ active: status === '' }" @click="status = ''">Все</button>
      <button v-for="(label, key) in requestStatusLabels" :key="key" type="button" :class="{ active: status === key }" @click="status = key">{{ label }}</button>
    </div>

    <div class="card">
      <div v-if="loading && !rows.length" class="loading-line">Загрузка...</div>
      <div v-else-if="!rows.length" class="empty">Заявок нет</div>
      <template v-else>
        <div class="table-wrap desktop-table">
          <table class="table">
            <thead>
              <tr>
                <th>Заявка</th>
                <th>Заказчик и объект</th>
                <th>Период</th>
                <th class="num">Работников</th>
                <th>Готовность</th>
                <th>Подпись</th>
                <th>Статус</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rows" :key="r.id" class="clickable" @click="router.push({ name: 'request', params: { id: r.id } })">
                <td>
                  <RouterLink class="cell-title" :to="{ name: 'request', params: { id: r.id } }" @click.stop>{{ r.number }}</RouterLink>
                  <div class="cell-sub">{{ r.work_type }}</div>
                </td>
                <td>
                  <div>{{ r.customer?.short_name }}</div>
                  <div class="cell-sub">{{ r.facility?.name }}</div>
                </td>
                <td class="nowrap">{{ formatDate(r.start_date) }} - {{ formatDate(r.end_date) }}</td>
                <td class="num">{{ r.workers_count }}</td>
                <td>
                  <span v-if="r.readiness" class="badge" :class="readyTones[r.readiness.status]">{{ readyLabels[r.readiness.status] }}</span>
                  <div v-if="r.readiness && r.readiness.counts.not_ready" class="cell-sub">не готовы: {{ r.readiness.counts.not_ready }}</div>
                </td>
                <td>
                  <span v-if="r.signed_at && r.signature_valid" class="badge tone-success">подписана</span>
                  <span v-else-if="r.signed_at" class="badge tone-warning">список изменён</span>
                  <span v-else class="muted small">нет</span>
                </td>
                <td><span class="badge" :class="requestStatusTones[r.status]">{{ requestStatusLabels[r.status] }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mobile-cards">
          <RouterLink v-for="r in rows" :key="r.id" class="m-card plain-link" :to="{ name: 'request', params: { id: r.id } }">
            <div class="row-between">
              <strong>{{ r.number }}</strong>
              <span class="badge" :class="requestStatusTones[r.status]">{{ requestStatusLabels[r.status] }}</span>
            </div>
            <div>{{ r.customer?.short_name }}, {{ r.work_type }}</div>
            <div class="m-meta">
              <span>{{ formatDate(r.start_date) }} - {{ formatDate(r.end_date) }}</span>
              <span>{{ r.workers_count }} чел.</span>
            </div>
            <div class="row">
              <span v-if="r.readiness" class="badge" :class="readyTones[r.readiness.status]">{{ readyLabels[r.readiness.status] }}</span>
              <span v-if="r.signed_at && r.signature_valid" class="badge tone-success">подписана</span>
            </div>
          </RouterLink>
        </div>
      </template>
    </div>

    <RequestFormModal v-if="creating" @close="creating = false" @saved="onCreated" />
  </div>
</template>

<style scoped>
.plain-link {
  color: inherit;
  text-decoration: none;
}
</style>
