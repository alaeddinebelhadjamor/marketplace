<script setup>
import { computed } from 'vue'
import { pct } from '@/utils/format'

const props = defineProps({
  title: { type: String, required: true },
  value: { type: String, required: true },
  change: { type: Number, default: null },
  previous: { type: String, default: '' },
  icon: { type: String, default: 'mdi-chart-line' },
})

const trend = computed(() => {
  if (props.change === null || props.change === undefined) return { color: 'grey', icon: 'mdi-minus' }
  if (props.change > 0) return { color: 'success', icon: 'mdi-trending-up' }
  if (props.change < 0) return { color: 'error', icon: 'mdi-trending-down' }
  return { color: 'grey', icon: 'mdi-trending-neutral' }
})
</script>

<template>
  <v-card class="h-100">
    <v-card-text>
      <div class="d-flex align-center text-medium-emphasis text-body-2">
        <v-icon size="18" class="mr-2">{{ icon }}</v-icon>{{ title }}
      </div>
      <div class="kpi-value font-weight-bold my-2">{{ value }}</div>
      <div class="d-flex align-center text-body-2">
        <v-chip :color="trend.color" size="small" variant="tonal" :prepend-icon="trend.icon">{{ pct(change) }}</v-chip>
        <span v-if="previous" class="ml-2 text-medium-emphasis">vs {{ previous }}</span>
      </div>
    </v-card-text>
  </v-card>
</template>

<style scoped>
.kpi-value { font-size: 1.6rem; line-height: 1.2; }
</style>
