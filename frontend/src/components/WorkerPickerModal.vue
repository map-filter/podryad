<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api'
import type { Readiness, WorkerBrief } from '@/api'
import Modal from './Modal.vue'
import ReadyBadge from './ReadyBadge.vue'
import { formatDate } from '@/utils/format'
import { errorMessage } from '@/utils/errors'

const props = defineProps<{ profileId: number; date: string; exclude: number[] }>()
const emit = defineEmits<{ close: []; pick: [number[]] }>()

const rows = ref<(WorkerBrief & { readiness: Readiness })[]>([])
const loading = ref(true)
const error = ref('')
const search = ref('')
const onlyReady = ref(true)
const selected = ref<number[]>([])

onMounted(async () => {
  try {
    rows.value = (await api.profileReadiness(props.profileId, props.date)).data.filter((w) => !props.exclude.includes(w.id))
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
})

const visible = computed(() => {
  const s = search.value.trim().toLowerCase()
  return rows.value.filter((w) => {
    if (onlyReady.value && w.readiness.status === 'not_ready') return false
    if (!s) return true
    return w.full_name.toLowerCase().includes(s) || w.iin.includes(s) || w.position.toLowerCase().includes(s)
  })
})

function toggle(id: number) {
  const i = selected.value.indexOf(id)
  if (i >= 0) selected.value.splice(i, 1)
  else selected.value.push(id)
}

function selectAllVisible() {
  for (const w of visible.value) if (!selected.value.includes(w.id)) selected.value.push(w.id)
}
</script>

<template>
  <Modal title="Добавить работников в заявку" :width="760" @close="emit('close')">
    <div class="stack">
      <p class="muted small">Готовность посчитана по профилю заявки на {{ formatDate(date) }}.</p>
      <div class="filters">
        <div class="field grow">
          <label for="wp-search">Поиск</label>
          <input id="wp-search" v-model="search" class="input" placeholder="ФИО, ИИН или должность" />
        </div>
        <label class="check"><input v-model="onlyReady" type="checkbox" /> Скрыть неготовых</label>
        <button class="btn btn-sm" type="button" @click="selectAllVisible">Выбрать всех видимых</button>
      </div>
      <div v-if="error" class="alert tone-danger">{{ error }}</div>
      <div v-if="loading" class="loading-line">Загрузка...</div>
      <div v-else-if="!visible.length" class="empty">Подходящих работников нет</div>
      <ul v-else class="pick-list">
        <li v-for="w in visible" :key="w.id">
          <label class="check pick">
            <input type="checkbox" :checked="selected.includes(w.id)" @change="toggle(w.id)" />
            <span class="pick-main">
              <strong>{{ w.full_name }}</strong>
              <span class="cell-sub">{{ w.position }}</span>
              <span v-if="w.readiness.problems.length" class="cell-sub danger">{{ w.readiness.problems.join('; ') }}</span>
            </span>
            <ReadyBadge :status="w.readiness.status" :threshold="w.readiness.threshold" :days-left="w.readiness.days_left" />
          </label>
        </li>
      </ul>
    </div>
    <template #footer>
      <button class="btn" type="button" @click="emit('close')">Отмена</button>
      <button class="btn btn-primary" type="button" :disabled="!selected.length" @click="emit('pick', selected)">Добавить ({{ selected.length }})</button>
    </template>
  </Modal>
</template>

<style scoped>
.pick-list {
  list-style: none;
  margin: 0;
  padding: 0;
  border: 1px solid var(--c-border);
  border-radius: var(--radius-sm);
  max-height: 55vh;
  overflow-y: auto;
}

.pick-list li {
  border-bottom: 1px solid var(--c-border);
}

.pick-list li:last-child {
  border-bottom: 0;
}

.pick {
  display: flex;
  width: 100%;
  padding: 9px 12px;
  gap: 10px;
  align-items: center;
}

.pick-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.danger {
  color: var(--c-danger);
}

@media (max-width: 560px) {
  .pick {
    flex-wrap: wrap;
  }
}
</style>
