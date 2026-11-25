<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { api } from '@/api'
import type { DocumentType, WorkerDocument } from '@/api'
import Modal from './Modal.vue'
import { errorMessage, fieldErrors } from '@/utils/errors'
import { categoryLabels, fileSize } from '@/utils/format'

const props = defineProps<{ workerId: number; types: DocumentType[]; doc?: WorkerDocument | null; presetTypeId?: number | null }>()
const emit = defineEmits<{ close: []; saved: [WorkerDocument] }>()

const form = reactive({
  document_type_id: props.doc?.document_type_id ?? props.presetTypeId ?? (null as number | null),
  number: props.doc?.number ?? '',
  issued_at: props.doc?.issued_at ?? '',
  valid_until: props.doc?.valid_until ?? '',
  issuer: props.doc?.issuer ?? '',
  group_level: props.doc?.group_level ? String(props.doc.group_level) : '',
  notes: props.doc?.notes ?? '',
})
const file = ref<File | null>(null)
const removeScan = ref(false)
const errors = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)

const type = computed(() => props.types.find((t) => t.id === form.document_type_id) ?? null)
const grouped = computed(() => {
  const g: Record<string, DocumentType[]> = {}
  for (const t of props.types) (g[t.category] ??= []).push(t)
  return Object.entries(g)
})
const autoHint = computed(() => {
  if (!type.value) return ''
  if (!type.value.validity_months) return 'Бессрочный документ, срок можно не указывать'
  return `Если срок не указан, он будет рассчитан от даты выдачи: ${type.value.validity_months} мес.`
})

function onFile(e: Event) {
  const input = e.target as HTMLInputElement
  file.value = input.files?.[0] ?? null
  if (file.value) removeScan.value = false
}

async function save() {
  busy.value = true
  errors.value = {}
  error.value = ''
  const fd = new FormData()
  fd.append('document_type_id', String(form.document_type_id ?? ''))
  for (const key of ['number', 'issued_at', 'valid_until', 'issuer', 'notes'] as const) fd.append(key, form[key] ?? '')
  if (type.value?.has_group) fd.append('group_level', form.group_level)
  if (file.value) fd.append('scan', file.value)
  if (removeScan.value) fd.append('remove_scan', '1')
  try {
    emit('saved', await api.saveDocument(props.workerId, props.doc?.id ?? null, fd))
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :title="doc ? 'Изменить документ' : 'Добавить документ'" :width="640" @close="emit('close')">
    <form id="doc-form" class="form-grid" @submit.prevent="save">
      <div class="field span-2">
        <label for="df-type">Тип документа</label>
        <select id="df-type" v-model="form.document_type_id" class="select" :class="{ invalid: errors.document_type_id }" required>
          <option :value="null" disabled>выберите</option>
          <optgroup v-for="[cat, list] in grouped" :key="cat" :label="categoryLabels[cat] ?? cat">
            <option v-for="t in list" :key="t.id" :value="t.id">{{ t.name }}</option>
          </optgroup>
        </select>
        <span v-if="errors.document_type_id" class="field-error">{{ errors.document_type_id }}</span>
        <span v-else-if="type?.description" class="field-hint">{{ type.description }}</span>
      </div>
      <div class="field">
        <label for="df-number">Номер</label>
        <input id="df-number" v-model="form.number" class="input" />
      </div>
      <div v-if="type?.has_group" class="field">
        <label for="df-group">Группа по электробезопасности</label>
        <select id="df-group" v-model="form.group_level" class="select" :class="{ invalid: errors.group_level }">
          <option value="">выберите</option>
          <option v-for="(g, i) in ['I', 'II', 'III', 'IV', 'V']" :key="g" :value="String(i + 1)">{{ g }}</option>
        </select>
        <span v-if="errors.group_level" class="field-error">{{ errors.group_level }}</span>
      </div>
      <div v-else class="field">
        <label for="df-issuer">Кем выдан</label>
        <input id="df-issuer" v-model="form.issuer" class="input" />
      </div>
      <div class="field">
        <label for="df-issued">Дата выдачи</label>
        <input id="df-issued" v-model="form.issued_at" class="input" type="date" />
      </div>
      <div class="field">
        <label for="df-until">Действует до</label>
        <input id="df-until" v-model="form.valid_until" class="input" :class="{ invalid: errors.valid_until }" type="date" />
        <span v-if="errors.valid_until" class="field-error">{{ errors.valid_until }}</span>
        <span v-else class="field-hint">{{ autoHint }}</span>
      </div>
      <div v-if="type?.has_group" class="field span-2">
        <label for="df-issuer2">Кем выдан</label>
        <input id="df-issuer2" v-model="form.issuer" class="input" />
      </div>
      <div class="field span-2">
        <label for="df-scan">Скан или фото (PDF, JPG, PNG до 10 МБ)</label>
        <input id="df-scan" class="input" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" @change="onFile" />
        <span v-if="errors.scan" class="field-error">{{ errors.scan }}</span>
        <span v-else-if="file" class="field-hint">{{ file.name }}, {{ fileSize(file.size) }}</span>
        <label v-else-if="doc?.has_scan" class="check small"><input v-model="removeScan" type="checkbox" /> Удалить загруженный скан ({{ doc.scan_name }})</label>
      </div>
      <div class="field span-2">
        <label for="df-notes">Примечание</label>
        <input id="df-notes" v-model="form.notes" class="input" />
      </div>
      <div v-if="error" class="alert tone-danger span-2">{{ error }}</div>
    </form>
    <template #footer>
      <button class="btn" type="button" @click="emit('close')">Отмена</button>
      <button class="btn btn-primary" type="submit" form="doc-form" :disabled="busy"><span v-if="busy" class="spinner" />Сохранить</button>
    </template>
  </Modal>
</template>
