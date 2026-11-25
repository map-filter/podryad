<script setup lang="ts">
import { computed } from 'vue'
import { fileUrl } from '@/api'
import Modal from './Modal.vue'
import Icon from './Icon.vue'

const props = defineProps<{ docId: number; title: string; mime: string | null }>()
const emit = defineEmits<{ close: [] }>()
const url = computed(() => fileUrl(`/documents/${props.docId}/scan`))
const isPdf = computed(() => props.mime === 'application/pdf')
</script>

<template>
  <Modal :title="title" :width="980" @close="emit('close')">
    <div class="viewer">
      <iframe v-if="isPdf" :src="url" title="Скан документа" />
      <img v-else :src="url" alt="Скан документа" />
    </div>
    <template #footer>
      <a class="btn" :href="url" target="_blank" rel="noopener"><Icon name="external" />Открыть в новой вкладке</a>
      <button class="btn btn-primary" type="button" @click="emit('close')">Закрыть</button>
    </template>
  </Modal>
</template>

<style scoped>
.viewer {
  display: flex;
  justify-content: center;
  background: #eef1f5;
  border-radius: var(--radius-sm);
  padding: 10px;
}

.viewer img {
  max-height: 70vh;
  object-fit: contain;
  box-shadow: var(--shadow);
}

.viewer iframe {
  width: 100%;
  height: 70vh;
  border: 0;
  background: #fff;
}
</style>
