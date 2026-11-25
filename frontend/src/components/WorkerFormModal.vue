<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { api } from '@/api'
import type { WorkerBrief, WorkerCard } from '@/api'
import Modal from './Modal.vue'
import { errorMessage, fieldErrors } from '@/utils/errors'
import { validIin, iinBirthDate } from '@/utils/iin'
import { formatDate } from '@/utils/format'

const props = defineProps<{ worker?: WorkerBrief | null; sites?: string[] }>()
const emit = defineEmits<{ close: []; saved: [WorkerCard] }>()

const form = reactive({
  last_name: props.worker?.last_name ?? '',
  first_name: props.worker?.first_name ?? '',
  middle_name: props.worker?.middle_name ?? '',
  iin: props.worker?.iin ?? '',
  position: props.worker?.position ?? '',
  grade: props.worker?.grade ? String(props.worker.grade) : '',
  site: props.worker?.site ?? '',
  phone: props.worker?.phone ?? '',
  hired_at: props.worker?.hired_at ?? '',
  notes: props.worker?.notes ?? '',
  is_active: props.worker?.is_active ?? true,
})
const errors = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)

const iinHint = computed(() => {
  const v = form.iin.replace(/\D/g, '')
  if (v.length < 12) return ''
  if (!validIin(v)) return 'invalid'
  const d = iinBirthDate(v)
  return d ? `Дата рождения по ИИН: ${formatDate(d)}` : 'invalid'
})

async function save() {
  busy.value = true
  errors.value = {}
  error.value = ''
  const body = { ...form, iin: form.iin.replace(/\D/g, ''), grade: form.grade ? Number(form.grade) : null, hired_at: form.hired_at || null }
  try {
    const res = props.worker ? await api.updateWorker(props.worker.id, body) : await api.createWorker(body)
    emit('saved', res)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :title="worker ? 'Изменить данные работника' : 'Новый работник'" :width="680" @close="emit('close')">
    <form id="worker-form" class="form-grid" @submit.prevent="save">
      <div class="field">
        <label for="wf-last">Фамилия</label>
        <input id="wf-last" v-model="form.last_name" class="input" :class="{ invalid: errors.last_name }" required />
        <span v-if="errors.last_name" class="field-error">{{ errors.last_name }}</span>
      </div>
      <div class="field">
        <label for="wf-first">Имя</label>
        <input id="wf-first" v-model="form.first_name" class="input" :class="{ invalid: errors.first_name }" required />
        <span v-if="errors.first_name" class="field-error">{{ errors.first_name }}</span>
      </div>
      <div class="field">
        <label for="wf-middle">Отчество</label>
        <input id="wf-middle" v-model="form.middle_name" class="input" />
      </div>
      <div class="field">
        <label for="wf-iin">ИИН</label>
        <input id="wf-iin" v-model="form.iin" class="input mono" :class="{ invalid: errors.iin || iinHint === 'invalid' }" inputmode="numeric" maxlength="12" required />
        <span v-if="errors.iin" class="field-error">{{ errors.iin }}</span>
        <span v-else-if="iinHint === 'invalid'" class="field-error">ИИН не прошёл проверку контрольной суммы</span>
        <span v-else-if="iinHint" class="field-hint">{{ iinHint }}</span>
        <span v-else class="field-hint">12 цифр, проверяется контрольная сумма</span>
      </div>
      <div class="field span-2">
        <label for="wf-pos">Должность</label>
        <input id="wf-pos" v-model="form.position" class="input" :class="{ invalid: errors.position }" required />
        <span v-if="errors.position" class="field-error">{{ errors.position }}</span>
      </div>
      <div class="field">
        <label for="wf-grade">Разряд</label>
        <select id="wf-grade" v-model="form.grade" class="select">
          <option value="">не указан</option>
          <option v-for="g in 8" :key="g" :value="String(g)">{{ g }} разряд</option>
        </select>
      </div>
      <div class="field">
        <label for="wf-site">Участок</label>
        <input id="wf-site" v-model="form.site" class="input" list="wf-sites" />
        <datalist id="wf-sites">
          <option v-for="s in sites ?? []" :key="s" :value="s" />
        </datalist>
      </div>
      <div class="field">
        <label for="wf-phone">Телефон</label>
        <input id="wf-phone" v-model="form.phone" class="input" inputmode="tel" />
      </div>
      <div class="field">
        <label for="wf-hired">Дата приёма</label>
        <input id="wf-hired" v-model="form.hired_at" class="input" type="date" />
      </div>
      <div class="field span-2">
        <label for="wf-notes">Примечание</label>
        <textarea id="wf-notes" v-model="form.notes" class="textarea" rows="2" />
      </div>
      <label v-if="worker" class="check span-2"><input v-model="form.is_active" type="checkbox" /> Работает (снимите, чтобы убрать в архив)</label>
      <div v-if="error" class="alert tone-danger span-2">{{ error }}</div>
    </form>
    <template #footer>
      <button class="btn" type="button" @click="emit('close')">Отмена</button>
      <button class="btn btn-primary" type="submit" form="worker-form" :disabled="busy">
        <span v-if="busy" class="spinner" />Сохранить
      </button>
    </template>
  </Modal>
</template>
