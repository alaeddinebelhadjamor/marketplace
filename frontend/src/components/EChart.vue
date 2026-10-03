<script setup>
// Graphique ECharts : seuls les modules utilisés sont chargés (bundle plus léger).
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { BarChart, LineChart } from 'echarts/charts'
import { DataZoomComponent, GridComponent, LegendComponent, TooltipComponent } from 'echarts/components'
import VChart from 'vue-echarts'

use([CanvasRenderer, LineChart, BarChart, GridComponent, TooltipComponent, LegendComponent, DataZoomComponent])

defineProps({
  option: { type: Object, required: true },
  height: { type: String, default: '320px' },
  label: { type: String, default: 'Graphique' },
})
</script>

<template>
  <div role="img" :aria-label="label" :style="{ height }">
    <v-chart :option="option" autoresize />
  </div>
</template>
