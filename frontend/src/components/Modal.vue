<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import Icon from './Icon.vue'

withDefaults(defineProps<{ title: string; width?: number; side?: boolean }>(), { width: 640, side: false })
const emit = defineEmits<{ close: [] }>()

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
}

onMounted(() => {
  document.addEventListener('keydown', onKey)
  document.body.style.overflow = 'hidden'
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <div class="overlay" :class="{ side }" @mousedown.self="emit('close')">
      <div class="dialog" role="dialog" aria-modal="true" :aria-label="title" :style="{ maxWidth: `${width}px` }">
        <header class="dialog-head">
          <h2 class="break">{{ title }}</h2>
          <button class="btn btn-ghost btn-icon" type="button" aria-label="Закрыть" @click="emit('close')">
            <Icon name="close" />
          </button>
        </header>
        <div class="dialog-body">
          <slot />
        </div>
        <footer v-if="$slots.footer" class="dialog-foot">
          <slot name="footer" />
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 35, 34, 0.42);
  z-index: 100;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 5vh 16px;
  overflow-y: auto;
}

.overlay.side {
  justify-content: flex-end;
  padding: 0;
  align-items: stretch;
}

.dialog {
  background: var(--c-surface);
  border-radius: var(--radius);
  box-shadow: var(--shadow-lg);
  width: 100%;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.side .dialog {
  border-radius: 0;
  min-height: 100%;
  max-height: 100%;
}

.dialog-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 18px;
  border-bottom: 1px solid var(--c-border);
}

.dialog-head h2 {
  min-width: 0;
}

.dialog-body {
  padding: 18px;
  min-width: 0;
}

.side .dialog-body {
  flex: 1;
  overflow-y: auto;
}

.dialog-foot {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px;
  padding: 14px 18px;
  border-top: 1px solid var(--c-border);
  background: var(--c-surface-2);
  border-radius: 0 0 var(--radius) var(--radius);
}

@media (max-width: 700px) {
  .overlay {
    padding: 0;
    align-items: stretch;
  }

  .dialog {
    border-radius: 0;
    min-height: 100%;
  }

  .dialog-body {
    padding: 14px;
  }

  .dialog-foot {
    position: sticky;
    bottom: 0;
    border-radius: 0;
  }

  .dialog-foot :deep(.btn) {
    flex: 1 1 auto;
  }
}
</style>
