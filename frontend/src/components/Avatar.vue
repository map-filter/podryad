<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { fileUrl } from '@/api'

const props = withDefaults(
  defineProps<{ workerId: number; hasPhoto: boolean; name: string; version?: number | null; size?: number }>(),
  { size: 38, version: null },
)
const failed = ref(false)
watch(
  () => props.version,
  () => (failed.value = false),
)
const initials = computed(() => {
  const parts = props.name.split(/\s+/).filter(Boolean)
  return ((parts[1]?.[0] ?? '') + (parts[0]?.[0] ?? '')).toUpperCase()
})
const src = computed(() => fileUrl(`/workers/${props.workerId}/photo`, props.version))
</script>

<template>
  <img
    v-if="hasPhoto && !failed"
    class="avatar"
    :src="src"
    :alt="name"
    :style="{ width: `${size}px`, height: `${size}px` }"
    loading="lazy"
    @error="failed = true"
  />
  <span v-else class="avatar" :style="{ width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size / 2.9)}px` }">{{ initials }}</span>
</template>
