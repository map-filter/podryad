<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api'
import type { RequestBrief } from '@/api'
import { notifyError } from '@/stores/toast'
import { session } from '@/stores/session'
import { formatDate, formatDateTime, readyLabels, readyTones, requestStatusLabels, requestStatusTones } from '@/utils/format'

const router = useRouter()
const rows = ref<RequestBrief[]>([])
const loading = ref(true)
const status = ref('')

async function load() {
  loading.value = true
  try {
    rows.value = (await api.customerRequests({ status: status.value })).data
  } catch (e) {
    notifyError(e)
  } finally {
    loading.value = false
  }
}

watch(status, load)
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div>
        <h1>Заявки подрядчиков на допуск</h1>
        <p>{{ session.customer?.name }}: поданные подрядными организациями списки работников с документами и подписью ЭЦП.</p>
      </div>
    </div>
    <div class="segmented">
      <button type="button" :class="{ active: status === '' }" @click="status = ''">Все</button>
      <button type="button" :class="{ active: status === 'submitted' }" @click="status = 'submitted'">Ждут проверки</button>
      <button type="button" :class="{ active: status === 'approved' }" @click="status = 'approved'">Допущены</button>
      <button type="button" :class="{ active: status === 'remarks' }" @click="status = 'remarks'">Замечания</button>
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
                <th>Подрядчик</th>
                <th>Объект и период</th>
                <th class="num">Работников</th>
                <th>Проверка документов</th>
                <th>Статус</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rows" :key="r.id" class="clickable" @click="router.push({ name: 'customer-request', params: { id: r.id } })">
                <td>
                  <RouterLink class="cell-title" :to="{ name: 'customer-request', params: { id: r.id } }" @click.stop>{{ r.number }}</RouterLink>
                  <div class="cell-sub">подана {{ formatDateTime(r.submitted_at) }}</div>
                </td>
                <td>
                  <div>{{ r.organization?.name }}</div>
                  <div class="cell-sub">БИН {{ r.organization?.bin }}</div>
                </td>
                <td>
                  <div>{{ r.facility?.name ?? 'объект не указан' }}</div>
                  <div class="cell-sub">{{ r.work_type }}, {{ formatDate(r.start_date) }} - {{ formatDate(r.end_date) }}</div>
                </td>
                <td class="num">{{ r.workers_count }}</td>
                <td><span v-if="r.readiness" class="badge" :class="readyTones[r.readiness.status]">{{ readyLabels[r.readiness.status] }}</span></td>
                <td><span class="badge" :class="requestStatusTones[r.status]">{{ r.status === 'submitted' ? 'Ждёт проверки' : requestStatusLabels[r.status] }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mobile-cards">
          <RouterLink v-for="r in rows" :key="r.id" class="m-card plain-link" :to="{ name: 'customer-request', params: { id: r.id } }">
            <div class="row-between">
              <strong>{{ r.number }}</strong>
              <span class="badge" :class="requestStatusTones[r.status]">{{ r.status === 'submitted' ? 'Ждёт проверки' : requestStatusLabels[r.status] }}</span>
            </div>
            <div>{{ r.organization?.name }}</div>
            <div class="m-meta">
              <span>{{ formatDate(r.start_date) }} - {{ formatDate(r.end_date) }}</span>
              <span>{{ r.workers_count }} чел.</span>
            </div>
          </RouterLink>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.plain-link {
  color: inherit;
  text-decoration: none;
}
</style>
