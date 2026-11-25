<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api'
import type { DocumentType } from '@/api'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { session } from '@/stores/session'
import { notify, notifyError } from '@/stores/toast'
import { categoryLabels } from '@/utils/format'
import { errorMessage, fieldErrors } from '@/utils/errors'

const types = ref<DocumentType[]>([])
const editing = ref<DocumentType | 'new' | null>(null)
const form = reactive({ name: '', short_name: '', category: 'ot', validity_months: '', has_group: false, is_basic: false, description: '' })
const errors = ref<Record<string, string>>({})
const error = ref('')
const canEdit = computed(() => !!session.user?.can_edit)

async function load() {
  try {
    types.value = (await api.documentTypes()).data
  } catch (e) {
    notifyError(e)
  }
}

function open(t: DocumentType | 'new') {
  editing.value = t
  errors.value = {}
  error.value = ''
  const src = t === 'new' ? null : t
  Object.assign(form, {
    name: src?.name ?? '',
    short_name: src?.short_name ?? '',
    category: src?.category ?? 'ot',
    validity_months: src?.validity_months ? String(src.validity_months) : '',
    has_group: src?.has_group ?? false,
    is_basic: src?.is_basic ?? false,
    description: src?.description ?? '',
  })
}

async function save() {
  const id = editing.value && editing.value !== 'new' ? editing.value.id : null
  try {
    await api.saveDocumentType(id, { ...form, validity_months: form.validity_months ? Number(form.validity_months) : null })
    editing.value = null
    notify('Сохранено')
    load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  }
}

async function remove(t: DocumentType) {
  if (!confirm(`Удалить тип «${t.name}»?`)) return
  try {
    await api.deleteDocumentType(t.id)
    notify('Удалено')
    load()
  } catch (e) {
    notifyError(e)
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div>
        <h1>Справочник документов</h1>
        <p>Типы удостоверений и допусков с типичными сроками действия. Сроки примерные, уточняйте по своим учебным центрам и требованиям заказчика. «Базовые» нужны каждому работнику и формируют общий светофор.</p>
      </div>
      <div class="page-actions">
        <button v-if="canEdit" class="btn btn-primary" type="button" @click="open('new')"><Icon name="plus" />Новый тип</button>
      </div>
    </div>
    <div class="card">
      <div class="table-wrap desktop-table">
        <table class="table">
          <thead>
            <tr>
              <th>Документ</th>
              <th>Раздел</th>
              <th class="num">Срок</th>
              <th>Признаки</th>
              <th class="num">Выдано</th>
              <th v-if="canEdit" class="actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in types" :key="t.id">
              <td>
                <div class="cell-title">{{ t.name }}</div>
                <div class="cell-sub">{{ t.short_name }}<template v-if="t.description"> · {{ t.description }}</template></div>
              </td>
              <td class="small">{{ categoryLabels[t.category] ?? t.category }}</td>
              <td class="num">{{ t.validity_months ? `${t.validity_months} мес.` : 'бессрочно' }}</td>
              <td>
                <div class="chips">
                  <span v-if="t.is_basic" class="badge tone-accent plain">базовый</span>
                  <span v-if="t.has_group" class="badge tone-info plain">группа I-V</span>
                </div>
              </td>
              <td class="num">{{ t.usage }}</td>
              <td v-if="canEdit" class="actions">
                <button class="btn btn-sm btn-ghost btn-icon" type="button" aria-label="Изменить" title="Изменить" @click="open(t)"><Icon name="edit" :size="16" /></button>
                <button class="btn btn-sm btn-ghost btn-icon" type="button" aria-label="Удалить" title="Удалить" :disabled="!!t.usage" @click="remove(t)"><Icon name="trash" :size="16" /></button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="mobile-cards">
        <div v-for="t in types" :key="t.id" class="m-card">
          <div class="cell-title">{{ t.name }}</div>
          <div class="m-meta">
            <span>{{ t.validity_months ? `${t.validity_months} мес.` : 'бессрочно' }}</span>
            <span>{{ categoryLabels[t.category] }}</span>
            <span>выдано: {{ t.usage }}</span>
          </div>
          <div class="chips">
            <span v-if="t.is_basic" class="badge tone-accent plain">базовый</span>
            <span v-if="t.has_group" class="badge tone-info plain">группа I-V</span>
            <button v-if="canEdit" class="btn btn-sm" type="button" @click="open(t)">Изменить</button>
          </div>
        </div>
      </div>
    </div>

    <Modal v-if="editing" :title="editing === 'new' ? 'Новый тип документа' : 'Тип документа'" :width="620" @close="editing = null">
      <form id="type-form" class="form-grid" @submit.prevent="save">
        <div class="field span-2">
          <label for="tf-name">Название</label>
          <input id="tf-name" v-model="form.name" class="input" :class="{ invalid: errors.name }" required />
          <span v-if="errors.name" class="field-error">{{ errors.name }}</span>
        </div>
        <div class="field">
          <label for="tf-short">Краткое название</label>
          <input id="tf-short" v-model="form.short_name" class="input" :class="{ invalid: errors.short_name }" required />
          <span v-if="errors.short_name" class="field-error">{{ errors.short_name }}</span>
        </div>
        <div class="field">
          <label for="tf-cat">Раздел</label>
          <select id="tf-cat" v-model="form.category" class="select">
            <option v-for="(label, key) in categoryLabels" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
        <div class="field">
          <label for="tf-validity">Срок действия, мес.</label>
          <input id="tf-validity" v-model="form.validity_months" class="input" type="number" min="1" max="240" placeholder="пусто: бессрочно" />
        </div>
        <div class="field">
          <span class="field-label">Признаки</span>
          <label class="check"><input v-model="form.has_group" type="checkbox" /> С группой I-V (ЭБ)</label>
          <label class="check"><input v-model="form.is_basic" type="checkbox" /> Базовый, нужен всем</label>
        </div>
        <div class="field span-2">
          <label for="tf-desc">Пояснение</label>
          <textarea id="tf-desc" v-model="form.description" class="textarea" rows="2" />
        </div>
        <div v-if="error" class="alert tone-danger span-2">{{ error }}</div>
      </form>
      <template #footer>
        <button class="btn" type="button" @click="editing = null">Отмена</button>
        <button class="btn btn-primary" type="submit" form="type-form">Сохранить</button>
      </template>
    </Modal>
  </div>
</template>
