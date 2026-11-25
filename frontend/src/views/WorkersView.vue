<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api'
import type { WorkerCard, WorkerRow } from '@/api'
import Avatar from '@/components/Avatar.vue'
import Icon from '@/components/Icon.vue'
import ReadyBadge from '@/components/ReadyBadge.vue'
import WorkerFormModal from '@/components/WorkerFormModal.vue'
import { session } from '@/stores/session'
import { notify, notifyError } from '@/stores/toast'
import { docTone, docText } from '@/utils/format'
import { download } from '@/utils/download'

const route = useRoute()
const router = useRouter()
const rows = ref<WorkerRow[]>([])
const meta = ref<{ sites: string[]; positions: string[] }>({ sites: [], positions: [] })
const loading = ref(true)
const creating = ref(false)
const filters = reactive({
  search: typeof route.query.search === 'string' ? route.query.search : '',
  status: typeof route.query.status === 'string' ? route.query.status : '',
  site: typeof route.query.site === 'string' ? route.query.site : '',
  position: '',
  active: '1',
})
const canEdit = computed(() => !!session.user?.can_edit)

let timer: ReturnType<typeof setTimeout> | null = null
async function load() {
  loading.value = true
  try {
    const res = await api.workers({ ...filters })
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    notifyError(e)
  } finally {
    loading.value = false
  }
}

watch(
  () => ({ ...filters }),
  () => {
    if (timer) clearTimeout(timer)
    timer = setTimeout(load, 250)
  },
)

const counts = computed(() => {
  const c = { ready: 0, expiring: 0, not_ready: 0 }
  for (const r of rows.value) c[r.readiness.status]++
  return c
})

function onCreated(w: WorkerCard) {
  creating.value = false
  notify('Работник добавлен, теперь внесите документы')
  router.push({ name: 'worker', params: { id: w.id } })
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div>
        <h1>Работники</h1>
        <p>Светофор показывает готовность по базовым документам на сегодня. Под конкретный объект проверка идёт по профилю требований в заявке.</p>
      </div>
      <div class="page-actions">
        <button class="btn" type="button" @click="download('/exports/registry', 'Реестр_сроков.xlsx')"><Icon name="download" />Реестр сроков</button>
        <RouterLink v-if="canEdit" class="btn" :to="{ name: 'import' }"><Icon name="upload" />Импорт из Excel</RouterLink>
        <button v-if="canEdit" class="btn btn-primary" type="button" @click="creating = true"><Icon name="plus" />Добавить работника</button>
      </div>
    </div>

    <div class="card card-body filters">
      <div class="field grow">
        <label for="f-search">Поиск</label>
        <input id="f-search" v-model="filters.search" class="input" placeholder="Фамилия, имя или ИИН" />
      </div>
      <div class="field">
        <label for="f-status">Готовность</label>
        <select id="f-status" v-model="filters.status" class="select">
          <option value="">все</option>
          <option value="ready">готов</option>
          <option value="expiring">истекает срок</option>
          <option value="not_ready">не готов</option>
        </select>
      </div>
      <div class="field">
        <label for="f-site">Участок</label>
        <select id="f-site" v-model="filters.site" class="select">
          <option value="">все</option>
          <option v-for="s in meta.sites" :key="s" :value="s">{{ s }}</option>
        </select>
      </div>
      <div class="field">
        <label for="f-pos">Должность</label>
        <select id="f-pos" v-model="filters.position" class="select">
          <option value="">все</option>
          <option v-for="p in meta.positions" :key="p" :value="p">{{ p }}</option>
        </select>
      </div>
      <div class="field">
        <label for="f-active">Состав</label>
        <select id="f-active" v-model="filters.active" class="select">
          <option value="1">работают</option>
          <option value="0">архив</option>
          <option value="all">все</option>
        </select>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <h2>{{ rows.length }} {{ rows.length === 1 ? 'работник' : 'работников' }}</h2>
        <div class="row">
          <span class="badge tone-success">готовы {{ counts.ready }}</span>
          <span class="badge tone-warning">истекает {{ counts.expiring }}</span>
          <span class="badge tone-danger">не готовы {{ counts.not_ready }}</span>
        </div>
      </div>
      <div v-if="loading && !rows.length" class="loading-line">Загрузка...</div>
      <div v-else-if="!rows.length" class="empty">Никого не нашли</div>
      <template v-else>
        <div class="table-wrap desktop-table">
          <table class="table">
            <thead>
              <tr>
                <th>Работник</th>
                <th>ИИН</th>
                <th>Участок</th>
                <th>Готовность</th>
                <th>Требует внимания</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="w in rows" :key="w.id" class="clickable" @click="router.push({ name: 'worker', params: { id: w.id } })">
                <td>
                  <div class="person">
                    <Avatar :worker-id="w.id" :has-photo="w.has_photo" :name="w.full_name" :version="w.photo_version" />
                    <div>
                      <RouterLink class="cell-title link-plain" :to="{ name: 'worker', params: { id: w.id } }" @click.stop>{{ w.full_name }}</RouterLink>
                      <div class="cell-sub">{{ w.position }}<template v-if="w.grade">, {{ w.grade }} разряд</template></div>
                    </div>
                  </div>
                </td>
                <td class="mono nowrap">{{ w.iin }}</td>
                <td class="small">{{ w.site }}</td>
                <td><ReadyBadge :status="w.readiness.status" :threshold="w.readiness.threshold" :days-left="w.readiness.days_left" /></td>
                <td>
                  <div class="chips">
                    <span v-for="a in w.attention" :key="a.type_short + a.valid_until" class="badge" :class="docTone(a.state, a.days_left !== null && a.days_left <= 7 ? 7 : 30)">
                      {{ a.type_short }}: {{ docText(a.state, a.days_left).toLowerCase() }}
                    </span>
                    <span v-for="p in w.readiness.problems.filter((x) => x.startsWith('нет'))" :key="p" class="badge tone-danger">{{ p }}</span>
                    <span v-if="!w.attention.length && !w.readiness.problems.length" class="muted small">всё в порядке</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mobile-cards">
          <RouterLink v-for="w in rows" :key="w.id" class="m-card link-plain" :to="{ name: 'worker', params: { id: w.id } }">
            <div class="person">
              <Avatar :worker-id="w.id" :has-photo="w.has_photo" :name="w.full_name" :version="w.photo_version" />
              <div>
                <div class="cell-title">{{ w.full_name }}</div>
                <div class="cell-sub">{{ w.position }}</div>
              </div>
            </div>
            <div class="m-meta">
              <span class="mono">ИИН {{ w.iin }}</span>
              <span v-if="w.site">{{ w.site }}</span>
            </div>
            <div class="chips">
              <ReadyBadge :status="w.readiness.status" :threshold="w.readiness.threshold" :days-left="w.readiness.days_left" />
              <span v-for="a in w.attention" :key="a.type_short + a.valid_until" class="badge" :class="docTone(a.state, a.days_left !== null && a.days_left <= 7 ? 7 : 30)">
                {{ a.type_short }}: {{ docText(a.state, a.days_left).toLowerCase() }}
              </span>
            </div>
          </RouterLink>
        </div>
      </template>
    </div>

    <WorkerFormModal v-if="creating" :sites="meta.sites" @close="creating = false" @saved="onCreated" />
  </div>
</template>

<style scoped>
.link-plain {
  color: inherit;
  text-decoration: none;
}

.table .chips {
  max-width: 420px;
}

.table .cell-title {
  display: block;
}
</style>
