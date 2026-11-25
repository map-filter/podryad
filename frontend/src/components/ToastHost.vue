<script setup lang="ts">
import { dismiss, toasts } from '@/stores/toast'
import Icon from './Icon.vue'
</script>

<template>
  <div class="toasts" aria-live="polite">
    <div v-for="t in toasts" :key="t.id" class="toast" :class="t.kind" role="status">
      <Icon :name="t.kind === 'error' ? 'alert' : t.kind === 'success' ? 'check' : 'info'" />
      <span class="break">{{ t.text }}</span>
      <button class="x" type="button" aria-label="Закрыть" @click="dismiss(t.id)"><Icon name="close" :size="14" /></button>
    </div>
  </div>
</template>

<style scoped>
.toasts {
  position: fixed;
  right: 16px;
  bottom: 16px;
  z-index: 200;
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-width: min(420px, calc(100vw - 32px));
}

.toast {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 11px 12px 11px 14px;
  background: #1f2f2e;
  color: #fff;
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-lg);
  font-size: 14px;
}

.toast span {
  flex: 1;
  min-width: 0;
}

.toast.success :deep(.ico) {
  color: #8ee0a8;
}

.toast.error {
  background: #7d2420;
}

.x {
  background: none;
  border: 0;
  color: inherit;
  opacity: 0.7;
  cursor: pointer;
  padding: 2px;
}
</style>
