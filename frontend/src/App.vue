<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'
import ToastHost from '@/components/ToastHost.vue'

const route = useRoute()
const ready = computed(() => route.matched.length > 0)
const isPublic = computed(() => route.meta.public === true)
</script>

<template>
  <RouterView v-if="ready && isPublic" />
  <AppLayout v-else-if="ready">
    <RouterView v-slot="{ Component, route: r }">
      <component :is="Component" :key="r.name" />
    </RouterView>
  </AppLayout>
  <ToastHost />
</template>
