<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/api'
import type { Customer, Profile, RequestFull } from '@/api'
import Modal from './Modal.vue'
import { errorMessage, fieldErrors } from '@/utils/errors'
import { todayIso } from '@/utils/format'

const props = defineProps<{ request?: RequestFull | null }>()
const emit = defineEmits<{ close: []; saved: [RequestFull] }>()

const customers = ref<Customer[]>([])
const profiles = ref<Profile[]>([])
const form = reactive({
  customer_id: props.request?.customer?.id ?? (null as number | null),
  facility_id: props.request?.facility?.id ?? (null as number | null),
  requirement_profile_id: props.request?.profile?.id ?? (null as number | null),
  work_type: props.request?.work_type ?? '',
  work_description: props.request?.work_description ?? '',
  start_date: props.request?.start_date ?? todayIso(1),
  end_date: props.request?.end_date ?? todayIso(15),
})
const errors = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)

const facilities = computed(() => customers.value.find((c) => c.id === form.customer_id)?.facilities ?? [])
const profileOptions = computed(() => {
  const own = profiles.value.filter((p) => p.customer_id === form.customer_id)
  const other = profiles.value.filter((p) => p.customer_id !== form.customer_id)
  return [...own, ...other]
})

watch(
  () => form.customer_id,
  (v, old) => {
    if (old === undefined || v === old) return
    if (!facilities.value.some((f) => f.id === form.facility_id)) form.facility_id = facilities.value[0]?.id ?? null
    const own = profiles.value.find((p) => p.customer_id === v)
    if (own && !props.request) form.requirement_profile_id = own.id
  },
)

watch(
  () => form.requirement_profile_id,
  (v) => {
    const p = profiles.value.find((x) => x.id === v)
    if (p && (!form.work_type || profiles.value.some((x) => x.work_type === form.work_type))) form.work_type = p.work_type
  },
)

onMounted(async () => {
  try {
    const [c, p] = await Promise.all([api.customers(), api.profiles()])
    customers.value = c.data
    profiles.value = p.data
  } catch (e) {
    error.value = errorMessage(e)
  }
})

async function save() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    const body = { ...form }
    const res = props.request ? await api.updateRequest(props.request.id, body) : await api.createRequest(body)
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
  <Modal :title="request ? `Заявка ${request.number}` : 'Новая заявка на допуск'" :width="680" @close="emit('close')">
    <form id="request-form" class="form-grid" @submit.prevent="save">
      <div class="field">
        <label for="rf-customer">Заказчик</label>
        <select id="rf-customer" v-model="form.customer_id" class="select" :class="{ invalid: errors.customer_id }" required>
          <option :value="null" disabled>выберите</option>
          <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.short_name }}: {{ c.name }}</option>
        </select>
        <span v-if="errors.customer_id" class="field-error">{{ errors.customer_id }}</span>
      </div>
      <div class="field">
        <label for="rf-facility">Объект</label>
        <select id="rf-facility" v-model="form.facility_id" class="select" :class="{ invalid: errors.facility_id }">
          <option :value="null">не указан</option>
          <option v-for="f in facilities" :key="f.id" :value="f.id">{{ f.name }}</option>
        </select>
        <span v-if="errors.facility_id" class="field-error">{{ errors.facility_id }}</span>
      </div>
      <div class="field span-2">
        <label for="rf-profile">Профиль требований</label>
        <select id="rf-profile" v-model="form.requirement_profile_id" class="select" :class="{ invalid: errors.requirement_profile_id }" required>
          <option :value="null" disabled>выберите</option>
          <option v-for="p in profileOptions" :key="p.id" :value="p.id">{{ p.name }} ({{ p.items.length }} док.)</option>
        </select>
        <span v-if="errors.requirement_profile_id" class="field-error">{{ errors.requirement_profile_id }}</span>
        <span v-else class="field-hint">Набор документов, который проверяется у каждого работника заявки</span>
      </div>
      <div class="field span-2">
        <label for="rf-work">Вид работ</label>
        <input id="rf-work" v-model="form.work_type" class="input" />
      </div>
      <div class="field span-2">
        <label for="rf-desc">Описание работ</label>
        <textarea id="rf-desc" v-model="form.work_description" class="textarea" rows="2" />
      </div>
      <div class="field">
        <label for="rf-start">Дата начала</label>
        <input id="rf-start" v-model="form.start_date" class="input" type="date" required />
        <span v-if="errors.start_date" class="field-error">{{ errors.start_date }}</span>
      </div>
      <div class="field">
        <label for="rf-end">Дата окончания</label>
        <input id="rf-end" v-model="form.end_date" class="input" :class="{ invalid: errors.end_date }" type="date" required />
        <span v-if="errors.end_date" class="field-error">{{ errors.end_date }}</span>
      </div>
      <div v-if="error" class="alert tone-danger span-2">{{ error }}</div>
    </form>
    <template #footer>
      <button class="btn" type="button" @click="emit('close')">Отмена</button>
      <button class="btn btn-primary" type="submit" form="request-form" :disabled="busy"><span v-if="busy" class="spinner" />{{ request ? 'Сохранить' : 'Создать и выбрать работников' }}</button>
    </template>
  </Modal>
</template>
