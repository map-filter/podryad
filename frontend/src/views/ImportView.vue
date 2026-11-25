<script setup lang="ts">
import { ref } from 'vue'
import { api } from '@/api'
import type { ImportResult } from '@/api'
import Icon from '@/components/Icon.vue'
import { notify } from '@/stores/toast'
import { download } from '@/utils/download'
import { errorMessage } from '@/utils/errors'

const file = ref<File | null>(null)
const result = ref<ImportResult | null>(null)
const busy = ref(false)
const error = ref('')
const done = ref(false)

function onFile(e: Event) {
  file.value = (e.target as HTMLInputElement).files?.[0] ?? null
  result.value = null
  done.value = false
  error.value = ''
}

async function run(dry: boolean) {
  if (!file.value) return
  busy.value = true
  error.value = ''
  try {
    result.value = await api.importFile(file.value, dry)
    if (!dry) {
      done.value = true
      notify('Импорт выполнен')
    }
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="page-head">
      <div>
        <h1>Импорт работников и документов из Excel</h1>
        <p>Файл .xlsx с листами «Работники» и «Документы». Сначала файл проверяется, ничего не записывая, затем загружается. Повторная загрузка того же файла не создаёт дублей.</p>
      </div>
      <div class="page-actions">
        <button class="btn" type="button" @click="download('/import/template', 'Шаблон_импорта_работников.xlsx')"><Icon name="download" />Скачать шаблон</button>
      </div>
    </div>

    <div class="card card-body stack">
      <div class="field">
        <label for="imp-file">Файл Excel</label>
        <input id="imp-file" class="input" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="onFile" />
        <span class="field-hint">Работники: Фамилия, Имя, Отчество, ИИН, Должность, Разряд, Участок, Телефон, Дата приёма. Документы: ИИН, Документ (название, краткое название или код), Номер, Дата выдачи, Действует до, Кем выдан, Группа ЭБ.</span>
      </div>
      <div class="row">
        <button class="btn" type="button" :disabled="!file || busy" @click="run(true)"><span v-if="busy" class="spinner" /><Icon v-else name="check" />Проверить файл</button>
        <button class="btn btn-primary" type="button" :disabled="!file || busy || !result || !result.dry_run || done" @click="run(false)"><Icon name="upload" />Загрузить в кабинет</button>
      </div>
      <div v-if="error" class="alert tone-danger">{{ error }}</div>
    </div>

    <div v-if="result" class="card">
      <div class="card-head">
        <h2>{{ result.dry_run ? 'Результат проверки' : 'Загружено' }}</h2>
      </div>
      <div class="card-body stack">
        <div class="stat-tiles">
          <div class="card stat"><span class="stat-label">Новых работников</span><span class="stat-value">{{ result.workers_new }}</span></div>
          <div class="card stat"><span class="stat-label">Обновятся</span><span class="stat-value">{{ result.workers_updated }}</span></div>
          <div class="card stat"><span class="stat-label">Документов</span><span class="stat-value">{{ result.documents }}</span></div>
          <div class="card stat"><span class="stat-label">Ошибок в строках</span><span class="stat-value">{{ result.errors.length }}</span></div>
        </div>
        <div v-if="result.documents_skipped" class="alert tone-info"><Icon name="info" />Пропущено как уже загруженные: {{ result.documents_skipped }}</div>
        <div v-if="result.errors.length" class="alert tone-danger">
          <Icon name="alert" />
          <div>
            <strong>Эти строки не будут загружены</strong>
            <ul class="plain"><li v-for="e in result.errors" :key="e">{{ e }}</li></ul>
          </div>
        </div>
        <div v-if="result.warnings.length" class="alert tone-warning">
          <Icon name="info" />
          <ul class="plain"><li v-for="w in result.warnings" :key="w">{{ w }}</li></ul>
        </div>
        <div v-if="result.preview.length" class="table-wrap">
          <table class="table">
            <thead><tr><th>ФИО</th><th>ИИН</th><th>Должность</th><th></th></tr></thead>
            <tbody>
              <tr v-for="p in result.preview" :key="p.iin">
                <td>{{ p.name }}</td>
                <td class="mono">{{ p.iin }}</td>
                <td>{{ p.position }}</td>
                <td><span class="badge" :class="p.exists ? 'tone-info' : 'tone-success'">{{ p.exists ? 'обновится' : 'новый' }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <RouterLink v-if="done" class="btn btn-primary" :to="{ name: 'workers' }">К списку работников</RouterLink>
      </div>
    </div>
  </div>
</template>

<style scoped>
.plain {
  margin: 4px 0 0;
  padding-left: 18px;
}
</style>
