<script setup lang="ts">
import { computed } from 'vue'
import type { ReadyStatus } from '@/api'
import { levelTone, readyText } from '@/utils/format'

const props = defineProps<{ status: ReadyStatus; threshold?: number | null; daysLeft?: number | null; short?: boolean }>()
const tone = computed(() => levelTone(props.status, props.threshold ?? null))
const text = computed(() => (props.short ? readyText(props.status, null, null) : readyText(props.status, props.threshold ?? null, props.daysLeft ?? null)))
</script>

<template>
  <span class="badge" :class="tone">{{ text }}</span>
</template>
