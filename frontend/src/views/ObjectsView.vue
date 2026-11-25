<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api'
import type { Customer, DocumentType, Facility, Profile } from '@/api'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { session } from '@/stores/session'
import { notify, notifyError } from '@/stores/toast'
import { categoryLabels, roman } from '@/utils/format'
import { errorMessage, fieldErrors } from '@/utils/errors'

const customers = ref<Customer[]>([])
const profiles = ref<Profile[]>([])
const types = ref<DocumentType[]>([])
const canEdit = computed(() => !!session.user?.can_edit)
const errors = ref<Record<string, string>>({})
const error = ref('')

const profileModal = ref<Profile | 'new' | null>(null)
const pform = reactive({ name: '', work_type: '', description: '', customer_id: null as number | null, items: {} as Record<number, { on: boolean; min_group: string }> })

const customerModal = ref<Customer | 'new' | null>(null)
const cform = reactive({ name: '', short_name: '', city: '', industry: '', contact: '' })

const facilityModal = ref<{ customer: Customer; facility: Facility | null } | null>(null)
const fform = reactive({ name: '', address: '', notes: '' })

async function load() {
  try {
    const [c, p, t] = await Promise.all([api.customers(), api.profiles(), api.documentTypes()])
    customers.value = c.data
    profiles.value = p.data
    types.value = t.data
  } catch (e) {
    notifyError(e)
  }
}

function reset() {
  errors.value = {}
  error.value = ''
}

function openProfile(p: Profile | 'new') {
  reset()
  profileModal.value = p
  const src = p === 'new' ? null : p
  pform.name = src?.name ?? ''
  pform.work_type = src?.work_type ?? ''
  pform.description = src?.description ?? ''
  pform.customer_id = src?.customer_id ?? null
  pform.items = {}
  for (const t of types.value) {
    const it = src?.items.find((i) => i.document_type_id === t.id)
    pform.items[t.id] = { on: !!it || (!src && t.is_basic), min_group: it?.min_group ? String(it.min_group) : '' }
  }
}

async function saveProfile() {
  const id = profileModal.value && profileModal.value !== 'new' ? profileModal.value.id : null
  const items = Object.entries(pform.items)
    .filter(([, v]) => v.on)
    .map(([k, v]) => ({ document_type_id: Number(k), min_group: v.min_group ? Number(v.min_group) : null }))
  try {
    await api.saveProfile(id, { name: pform.name, work_type: pform.work_type, description: pform.description, customer_id: pform.customer_id, items })
    profileModal.value = null
    notify('Профиль сохранён')
    load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  }
}

async function removeProfile(p: Profile) {
  if (!confirm(`Удалить профиль «${p.name}»?`)) return
  try {
    await api.deleteProfile(p.id)
    notify('Профиль удалён')
    load()
  } catch (e) {
    notifyError(e)
  }
}

function openCustomer(c: Customer | 'new') {
  reset()
  customerModal.value = c
  const src = c === 'new' ? null : c
  Object.assign(cform, { name: src?.name ?? '', short_name: src?.short_name ?? '', city: src?.city ?? '', industry: src?.industry ?? '', contact: src?.contact ?? '' })
}

async function saveCustomer() {
  const id = customerModal.value && customerModal.value !== 'new' ? customerModal.value.id : null
  try {
    await api.saveCustomer(id, { ...cform })
    customerModal.value = null
    notify('Заказчик сохранён')
    load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  }
}

function openFacility(customer: Customer, facility: Facility | null) {
  reset()
  facilityModal.value = { customer, facility }
  Object.assign(fform, { name: facility?.name ?? '', address: facility?.address ?? '', notes: facility?.notes ?? '' })
}

async function saveFacility() {
  if (!facilityModal.value) return
  try {
    await api.saveFacility(facilityModal.value.facility?.id ?? null, { ...fform, customer_id: facilityModal.value.customer.id })
    facilityModal.value = null
    notify('Объект сохранён')
    load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  }
}

const groupedTypes = computed(() => {
  const g: Record<string, DocumentType[]> = {}
  for (const t of types.value) (g[t.category] ??= []).push(t)
  return Object.entries(g)
})

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div>
        <h1>Объекты и профили требований</h1>
        <p>Профиль требований: какой набор документов нужен для вида работ у конкретного заказчика.</p>
      </div>
    </div>

    <div class="alert tone-info">
      <Icon name="info" />
      <div>
        <strong>Профили примерные.</strong> Наборы «ПНХЗ: огневые работы», «ГРЭС: работы в электроустановках», «ERG: работы на высоте» и другие составлены как пример. Требования каждого заказчика свои и меняются: сверьте профиль со стандартом заказчика по работе с подрядчиками и настройте его здесь.
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <h2>Профили требований ({{ profiles.length }})</h2>
        <button v-if="canEdit" class="btn btn-sm btn-primary" type="button" @click="openProfile('new')"><Icon name="plus" :size="15" />Новый профиль</button>
      </div>
      <div class="card-body profiles">
        <div v-for="p in profiles" :key="p.id" class="profile card">
          <div class="card-body stack">
            <div class="row-between">
              <h3 class="break">{{ p.name }}</h3>
              <div v-if="canEdit" class="row">
                <button class="btn btn-sm btn-ghost btn-icon" type="button" aria-label="Изменить" title="Изменить" @click="openProfile(p)"><Icon name="edit" :size="16" /></button>
                <button class="btn btn-sm btn-ghost btn-icon" type="button" aria-label="Удалить" title="Удалить" :disabled="!!p.requests_count" @click="removeProfile(p)"><Icon name="trash" :size="16" /></button>
              </div>
            </div>
            <div class="small text-2">{{ p.customer?.name ?? 'Любой заказчик' }} · {{ p.work_type }}</div>
            <p v-if="p.description" class="small muted">{{ p.description }}</p>
            <div class="chips">
              <span v-for="i in p.items" :key="i.document_type_id" class="badge plain tone-accent">{{ i.short_name }}<template v-if="i.min_group"> ≥ {{ roman(i.min_group) }}</template></span>
            </div>
            <div v-if="p.counts" class="row small">
              <span class="muted">Готовы на завтра:</span>
              <span class="badge tone-success">{{ p.counts.ready }}</span>
              <span class="badge tone-warning">{{ p.counts.expiring }}</span>
              <span class="badge tone-danger">{{ p.counts.not_ready }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <h2>Заказчики и объекты</h2>
        <button v-if="canEdit" class="btn btn-sm" type="button" @click="openCustomer('new')"><Icon name="plus" :size="15" />Заказчик</button>
      </div>
      <ul class="customers">
        <li v-for="c in customers" :key="c.id">
          <div class="row-between">
            <div>
              <strong>{{ c.short_name }}</strong>
              <span class="text-2"> · {{ c.name }}</span>
              <div class="cell-sub">{{ c.city }}<template v-if="c.industry">, {{ c.industry }}</template></div>
            </div>
            <div v-if="canEdit" class="row">
              <button class="btn btn-sm btn-ghost" type="button" @click="openCustomer(c)"><Icon name="edit" :size="15" />Изменить</button>
              <button class="btn btn-sm btn-ghost" type="button" @click="openFacility(c, null)"><Icon name="plus" :size="15" />Объект</button>
            </div>
          </div>
          <ul v-if="c.facilities?.length" class="facilities">
            <li v-for="f in c.facilities" :key="f.id">
              <Icon name="building" :size="16" />
              <div>
                <div>{{ f.name }}</div>
                <div class="cell-sub">{{ f.address }}<template v-if="f.notes"> · {{ f.notes }}</template></div>
              </div>
              <button v-if="canEdit" class="btn btn-sm btn-ghost btn-icon" type="button" aria-label="Изменить объект" title="Изменить объект" @click="openFacility(c, f)"><Icon name="edit" :size="15" /></button>
            </li>
          </ul>
        </li>
      </ul>
    </div>

    <Modal v-if="profileModal" :title="profileModal === 'new' ? 'Новый профиль требований' : 'Профиль требований'" :width="760" @close="profileModal = null">
      <form id="profile-form" class="stack" @submit.prevent="saveProfile">
        <div class="form-grid">
          <div class="field span-2">
            <label for="pf-name">Название</label>
            <input id="pf-name" v-model="pform.name" class="input" :class="{ invalid: errors.name }" placeholder="Например: ПНХЗ: огневые работы" required />
            <span v-if="errors.name" class="field-error">{{ errors.name }}</span>
          </div>
          <div class="field">
            <label for="pf-customer">Заказчик</label>
            <select id="pf-customer" v-model="pform.customer_id" class="select">
              <option :value="null">любой</option>
              <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.short_name }}</option>
            </select>
          </div>
          <div class="field">
            <label for="pf-work">Вид работ</label>
            <input id="pf-work" v-model="pform.work_type" class="input" :class="{ invalid: errors.work_type }" required />
          </div>
          <div class="field span-2">
            <label for="pf-desc">Пояснение</label>
            <input id="pf-desc" v-model="pform.description" class="input" />
          </div>
        </div>
        <div class="field-label">Нужные документы</div>
        <span v-if="errors.items" class="field-error">{{ errors.items }}</span>
        <div class="type-groups">
          <div v-for="[cat, list] in groupedTypes" :key="cat" class="type-group">
            <div class="cell-sub">{{ categoryLabels[cat] ?? cat }}</div>
            <div v-for="t in list" :key="t.id" class="type-row">
              <label class="check"><input v-model="pform.items[t.id].on" type="checkbox" /> {{ t.name }}</label>
              <select v-if="t.has_group && pform.items[t.id].on" v-model="pform.items[t.id].min_group" class="select group-select" aria-label="Минимальная группа">
                <option value="">любая группа</option>
                <option v-for="g in 5" :key="g" :value="String(g)">не ниже {{ roman(g) }}</option>
              </select>
            </div>
          </div>
        </div>
        <div v-if="error" class="alert tone-danger">{{ error }}</div>
      </form>
      <template #footer>
        <button class="btn" type="button" @click="profileModal = null">Отмена</button>
        <button class="btn btn-primary" type="submit" form="profile-form">Сохранить</button>
      </template>
    </Modal>

    <Modal v-if="customerModal" :title="customerModal === 'new' ? 'Новый заказчик' : 'Заказчик'" :width="560" @close="customerModal = null">
      <form id="customer-form" class="form-grid" @submit.prevent="saveCustomer">
        <div class="field span-2">
          <label for="cf-name">Полное название</label>
          <input id="cf-name" v-model="cform.name" class="input" required />
          <span v-if="errors.name" class="field-error">{{ errors.name }}</span>
        </div>
        <div class="field">
          <label for="cf-short">Краткое</label>
          <input id="cf-short" v-model="cform.short_name" class="input" required />
        </div>
        <div class="field">
          <label for="cf-city">Город</label>
          <input id="cf-city" v-model="cform.city" class="input" />
        </div>
        <div class="field span-2">
          <label for="cf-ind">Отрасль</label>
          <input id="cf-ind" v-model="cform.industry" class="input" />
        </div>
        <div v-if="error" class="alert tone-danger span-2">{{ error }}</div>
      </form>
      <template #footer>
        <button class="btn" type="button" @click="customerModal = null">Отмена</button>
        <button class="btn btn-primary" type="submit" form="customer-form">Сохранить</button>
      </template>
    </Modal>

    <Modal v-if="facilityModal" :title="`Объект: ${facilityModal.customer.short_name}`" :width="560" @close="facilityModal = null">
      <form id="facility-form" class="form-grid" @submit.prevent="saveFacility">
        <div class="field span-2">
          <label for="ff-name">Название</label>
          <input id="ff-name" v-model="fform.name" class="input" required />
          <span v-if="errors.name" class="field-error">{{ errors.name }}</span>
        </div>
        <div class="field span-2">
          <label for="ff-addr">Адрес</label>
          <input id="ff-addr" v-model="fform.address" class="input" />
        </div>
        <div class="field span-2">
          <label for="ff-notes">Особенности допуска</label>
          <textarea id="ff-notes" v-model="fform.notes" class="textarea" rows="2" />
        </div>
        <div v-if="error" class="alert tone-danger span-2">{{ error }}</div>
      </form>
      <template #footer>
        <button class="btn" type="button" @click="facilityModal = null">Отмена</button>
        <button class="btn btn-primary" type="submit" form="facility-form">Сохранить</button>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
.profiles {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr));
  gap: 14px;
}

.profile {
  box-shadow: none;
}

.customers {
  list-style: none;
  margin: 0;
  padding: 0;
}

.customers > li {
  padding: 14px 18px;
  border-bottom: 1px solid var(--c-border);
}

.customers > li:last-child {
  border-bottom: 0;
}

.facilities {
  list-style: none;
  margin: 10px 0 0;
  padding: 0 0 0 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.facilities li {
  display: flex;
  gap: 10px;
  align-items: flex-start;
}

.facilities li > div {
  flex: 1;
  min-width: 0;
}

.type-groups {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 20px;
}

.type-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.type-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.group-select {
  width: auto;
  min-height: 30px;
  padding-top: 3px;
  padding-bottom: 3px;
  font-size: 13px;
}

@media (max-width: 700px) {
  .type-groups {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
