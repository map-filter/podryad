<script setup lang="ts">
withDefaults(defineProps<{ size?: number }>(), { size: 30 })
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 32 32" aria-hidden="true">
    <rect width="32" height="32" rx="8" fill="#2c6aa3" />
    <path d="M9 23V12.5L16 8l7 4.5V23" fill="none" stroke="#fff" stroke-width="2.2" stroke-linejoin="round" />
    <path d="M12.5 17.5l2.6 2.6 4.6-5" fill="none" stroke="#ffd166" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
  </svg>
</template>
