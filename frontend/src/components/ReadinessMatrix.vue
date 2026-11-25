<script setup lang="ts">
import type { ProfileItem, RequestWorker, ReadinessItem } from '@/api'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import ReadyBadge from './ReadyBadge.vue'
import { formatDate, itemTones, roman } from '@/utils/format'

defineProps<{ workers: RequestWorker[]; items: ProfileItem[]; removable?: boolean; linkWorkers?: boolean; showDecision?: boolean }>()
const emit = defineEmits<{ remove: [number]; scan: [ReadinessItem, RequestWorker] }>()

function cell(w: RequestWorker, typeId: number): ReadinessItem | undefined {
  return w.readiness.items.find((i) => i.document_type_id === typeId)
}

function cellText(i: ReadinessItem | undefined): string {
  if (!i || i.state === 'missing') return 'нет'
  if (i.state === 'low_group') return `гр. ${roman(i.group_level)}`
  if (!i.valid_until) return 'бессрочно'
  return formatDate(i.valid_until).slice(0, 6) + formatDate(i.valid_until).slice(8)
}
</script>

<template>
  <div class="table-wrap desktop-table">
    <table class="table matrix">
      <thead>
        <tr>
          <th class="sticky-col">Работник</th>
          <th v-for="it in items" :key="it.document_type_id" :title="it.name">
            {{ it.short_name }}<template v-if="it.min_group"> ≥{{ roman(it.min_group) }}</template>
          </th>
          <th>Итог</th>
          <th v-if="showDecision">Заказчик</th>
          <th v-if="removable" class="actions"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="w in workers" :key="w.id">
          <td class="sticky-col">
            <div class="person">
              <Avatar :worker-id="w.id" :has-photo="w.has_photo" :name="w.full_name" :version="w.photo_version" :size="30" />
              <div>
                <RouterLink v-if="linkWorkers" class="cell-title name" :to="{ name: 'worker', params: { id: w.id } }">{{ w.full_name }}</RouterLink>
                <div v-else class="cell-title name">{{ w.full_name }}</div>
                <div class="cell-sub">{{ w.position }}, ИИН {{ w.iin }}</div>
              </div>
            </div>
          </td>
          <td v-for="it in items" :key="it.document_type_id" class="mcell">
            <button
              v-if="cell(w, it.document_type_id)?.has_scan"
              type="button"
              class="badge plain scan-btn"
              :class="itemTones[cell(w, it.document_type_id)!.state]"
              :title="cell(w, it.document_type_id)!.message + ' (открыть скан)'"
              @click="emit('scan', cell(w, it.document_type_id)!, w)"
            >
              {{ cellText(cell(w, it.document_type_id)) }}
            </button>
            <span v-else class="badge plain" :class="itemTones[cell(w, it.document_type_id)?.state ?? 'missing']" :title="cell(w, it.document_type_id)?.message">
              {{ cellText(cell(w, it.document_type_id)) }}
            </span>
          </td>
          <td><ReadyBadge :status="w.readiness.status" :threshold="w.readiness.threshold" :days-left="w.readiness.days_left" short /></td>
          <td v-if="showDecision">
            <span v-if="w.customer_decision === 'ok'" class="badge tone-success">допущен</span>
            <span v-else-if="w.customer_decision === 'remark'" class="badge tone-danger" :title="w.customer_comment ?? ''">замечание</span>
            <div v-if="w.customer_comment" class="cell-sub">{{ w.customer_comment }}</div>
          </td>
          <td v-if="removable" class="actions">
            <button class="btn btn-sm btn-ghost btn-icon" type="button" title="Убрать из заявки" aria-label="Убрать из заявки" @click="emit('remove', w.id)">
              <Icon name="close" :size="16" />
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
  <div class="mobile-cards">
    <div v-for="w in workers" :key="w.id" class="m-card">
      <div class="row-between">
        <div class="person">
          <Avatar :worker-id="w.id" :has-photo="w.has_photo" :name="w.full_name" :version="w.photo_version" :size="30" />
          <div>
            <div class="cell-title">{{ w.full_name }}</div>
            <div class="cell-sub">{{ w.position }}</div>
          </div>
        </div>
      </div>
      <div class="chips">
        <ReadyBadge :status="w.readiness.status" :threshold="w.readiness.threshold" :days-left="w.readiness.days_left" />
        <span v-if="showDecision && w.customer_decision === 'remark'" class="badge tone-danger">замечание</span>
      </div>
      <div class="chips">
        <span v-for="i in w.readiness.items" :key="i.document_type_id" class="badge plain" :class="itemTones[i.state]">{{ i.short_name }}: {{ cellText(i) }}</span>
      </div>
      <div v-if="w.customer_comment" class="cell-sub">Заказчик: {{ w.customer_comment }}</div>
      <div class="row">
        <RouterLink v-if="linkWorkers" class="btn btn-sm" :to="{ name: 'worker', params: { id: w.id } }">Карточка</RouterLink>
        <button v-if="removable" class="btn btn-sm" type="button" @click="emit('remove', w.id)">Убрать</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.matrix th,
.matrix td {
  padding-left: 8px;
  padding-right: 8px;
}

.matrix th {
  font-size: 12px;
}

.mcell {
  text-align: left;
  white-space: nowrap;
}

.mcell .badge {
  font-size: 12px;
  padding: 1px 7px;
}

.scan-btn {
  border: 0;
  cursor: pointer;
  text-decoration: underline dotted;
  text-underline-offset: 2px;
}

.name {
  display: block;
  min-width: 150px;
  text-decoration: none;
  color: var(--c-text);
}

.sticky-col {
  position: sticky;
  left: 0;
  background: var(--c-surface);
  z-index: 1;
}

thead .sticky-col {
  background: var(--c-surface-2);
}
</style>
