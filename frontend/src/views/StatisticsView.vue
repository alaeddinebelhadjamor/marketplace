<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import EChart from '@/components/EChart.vue'
import KpiCard from '@/components/KpiCard.vue'
import { useSkuTracking } from '@/composables/useSkuTracking'
import { api, errorMessage } from '@/services/api'
import { dt, isoDay, num } from '@/utils/format'
import { dateRangeError } from '@/utils/rules'

const PERIODS = [
  { value: '7', label: '7 jours' },
  { value: '30', label: '30 jours' },
  { value: '90', label: '90 jours' },
  { value: '365', label: 'Année' },
  { value: 'custom', label: 'Personnalisée' },
]

const period = ref('30')
const customFrom = ref(isoDay(Date.now() - 29 * 86400000))
const customTo = ref(isoDay())
const loading = ref(false)
const error = ref('')
const dashboard = ref(null)
const orders = ref([])
const skuInput = ref('')
const { tracked, message: trackMessage, add, remove } = useSkuTracking()

const range = computed(() => {
  if (period.value === 'custom') return { from: customFrom.value, to: customTo.value }
  const days = Number(period.value)
  return { from: isoDay(Date.now() - (days - 1) * 86400000), to: isoDay() }
})
const rangeError = computed(() => dateRangeError(range.value.from, range.value.to))

async function load() {
  if (rangeError.value) return
  loading.value = true
  error.value = ''
  try {
    const [d, o] = await Promise.all([
      api.get('/api/dashboard', { params: range.value }),
      api.get('/api/orders', { params: range.value }),
    ])
    dashboard.value = d.data
    orders.value = o.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

watch(range, load)
onMounted(load)

const k = computed(() => dashboard.value?.kpis)
const shortDay = (iso) => new Date(iso + 'T00:00:00').toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })

const revenueOption = computed(() => {
  const s = dashboard.value?.series
  if (!s) return {}
  return {
    tooltip: { trigger: 'axis', valueFormatter: (v) => dt(v) },
    legend: { top: 0, data: ['Période', 'Période précédente'] },
    grid: { left: 60, right: 20, top: 40, bottom: 56 },
    dataZoom: [{ type: 'inside' }, { type: 'slider', height: 18, bottom: 8 }],
    xAxis: { type: 'category', data: s.current.map((p) => shortDay(p.date)) },
    yAxis: { type: 'value', axisLabel: { formatter: (v) => `${num(v)} DT` } },
    series: [
      { name: 'Période', type: 'line', smooth: true, areaStyle: { opacity: 0.08 }, color: '#c70a0a', data: s.current.map((p) => p.revenue) },
      { name: 'Période précédente', type: 'line', smooth: true, lineStyle: { type: 'dashed' }, color: '#94a3b8', data: s.previous.map((p) => p.revenue) },
    ],
  }
})

const weekdayOption = computed(() => {
  const w = dashboard.value?.by_weekday
  if (!w) return {}
  return {
    tooltip: { trigger: 'axis', valueFormatter: (v) => dt(v) },
    grid: { left: 60, right: 10, top: 20, bottom: 30 },
    xAxis: { type: 'category', data: w.map((d) => d.day.slice(0, 3)) },
    yAxis: { type: 'value', axisLabel: { formatter: (v) => num(v) } },
    series: [{ type: 'bar', color: '#1f2a44', data: w.map((d) => d.revenue), barMaxWidth: 36 }],
  }
})

// Comparaison des références suivies : quantités vendues par jour.
const comparisonOption = computed(() => {
  if (!tracked.value.length || !dashboard.value) return null
  const days = dashboard.value.series.current.map((p) => p.date)
  return {
    tooltip: { trigger: 'axis' },
    legend: { type: 'scroll', top: 0 },
    grid: { left: 40, right: 20, top: 40, bottom: 30 },
    xAxis: { type: 'category', data: days.map(shortDay) },
    yAxis: { type: 'value', minInterval: 1 },
    series: tracked.value.map((t) => {
      const byDay = {}
      orders.value
        .filter((o) => o.sku.toUpperCase() === t.sku)
        .forEach((o) => {
          const d = isoDay(o.effective_at)
          byDay[d] = (byDay[d] || 0) + o.qty
        })
      return { name: `${t.name} [${t.sku}]`, type: 'line', smooth: true, color: t.color, data: days.map((d) => byDay[d] || 0) }
    }),
  }
})

function addSku() {
  const sku = skuInput.value.trim().toUpperCase()
  const found = orders.value.find((o) => o.sku.toUpperCase() === sku)
  if (add(sku, found?.product_name)) skuInput.value = ''
}
</script>

<template>
  <div class="d-flex flex-wrap align-center ga-3 mb-4">
    <div class="flex-grow-1">
      <h1 class="page-title">Tableau de bord</h1>
      <p class="page-subtitle">Ventes de la période comparées à la période précédente de même durée.</p>
    </div>
    <v-chip-group v-model="period" mandatory selected-class="bg-primary" class="period-group" aria-label="Période">
      <v-chip v-for="p in PERIODS" :key="p.value" :value="p.value" variant="outlined" filter>{{ p.label }}</v-chip>
    </v-chip-group>
  </div>

  <v-row v-if="period === 'custom'" dense class="mb-2">
    <v-col cols="6" md="3"><v-text-field v-model="customFrom" type="date" label="Du" hide-details="auto" /></v-col>
    <v-col cols="6" md="3"><v-text-field v-model="customTo" type="date" label="Au" hide-details="auto" /></v-col>
  </v-row>
  <v-alert v-if="rangeError" type="warning" variant="tonal" density="compact" class="mb-4">{{ rangeError }}</v-alert>
  <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">{{ error }}</v-alert>
  <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-2" />

  <template v-if="k">
    <v-row>
      <v-col cols="12" sm="6" lg="3"><KpiCard title="Chiffre d'affaires" icon="mdi-cash" :value="dt(k.revenue.value)" :change="k.revenue.change_pct" :previous="dt(k.revenue.previous)" /></v-col>
      <v-col cols="12" sm="6" lg="3"><KpiCard title="Commandes" icon="mdi-receipt-text-outline" :value="num(k.orders.value)" :change="k.orders.change_pct" :previous="num(k.orders.previous)" /></v-col>
      <v-col cols="12" sm="6" lg="3"><KpiCard title="Articles vendus" icon="mdi-package-variant" :value="num(k.items.value)" :change="k.items.change_pct" :previous="num(k.items.previous)" /></v-col>
      <v-col cols="12" sm="6" lg="3"><KpiCard title="Panier moyen" icon="mdi-cart-outline" :value="dt(k.average_basket.value)" :change="k.average_basket.change_pct" :previous="dt(k.average_basket.previous)" /></v-col>
    </v-row>

    <v-row>
      <v-col cols="12" lg="8">
        <v-card>
          <v-card-title class="text-subtitle-1">Évolution du chiffre d'affaires</v-card-title>
          <v-card-subtitle>Net estimé après commission ({{ Math.round(dashboard.commission_rate * 1000) / 10 }} %) : {{ dt(k.net_revenue.value) }}</v-card-subtitle>
          <v-card-text><EChart :option="revenueOption" label="Chiffre d'affaires par jour, période courante et précédente" /></v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" lg="4">
        <v-card class="h-100">
          <v-card-title class="text-subtitle-1">Meilleures ventes</v-card-title>
          <v-list v-if="dashboard.top_products.length" density="compact">
            <v-list-item v-for="(p, i) in dashboard.top_products" :key="p.sku">
              <template #prepend><v-avatar size="28" color="primary" variant="tonal" class="text-caption">{{ i + 1 }}</v-avatar></template>
              <v-list-item-title class="text-body-2">{{ p.name }}</v-list-item-title>
              <v-list-item-subtitle>{{ p.sku }} · {{ dt(p.revenue) }}</v-list-item-subtitle>
              <template #append><strong>{{ p.qty }}</strong></template>
            </v-list-item>
          </v-list>
          <v-card-text v-else class="text-medium-emphasis">Aucune vente sur la période.</v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-row>
      <v-col cols="12" lg="4">
        <v-card class="h-100">
          <v-card-title class="text-subtitle-1 text-wrap">CA par jour de la semaine</v-card-title>
          <v-card-text><EChart :option="weekdayOption" height="260px" label="Chiffre d'affaires par jour de la semaine" /></v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" lg="8">
        <v-card class="h-100">
          <v-card-title class="text-subtitle-1">Comparaison des ventes par produit</v-card-title>
          <v-card-subtitle>Superposez jusqu'à 5 références pour comparer leurs ventes.</v-card-subtitle>
          <v-card-text>
            <div class="d-flex ga-2 mb-2">
              <v-text-field v-model="skuInput" label="SKU du produit" density="compact" hide-details @keyup.enter="addSku" data-test="sku-input" />
              <v-btn color="primary" @click="addSku" data-test="sku-add">Ajouter</v-btn>
            </div>
            <v-alert v-if="trackMessage" type="info" variant="tonal" density="compact" class="mb-2" data-test="sku-message">{{ trackMessage }}</v-alert>
            <div class="d-flex flex-wrap ga-2 mb-2">
              <v-chip v-for="t in tracked" :key="t.sku" :color="t.color" variant="outlined" closable @click:close="remove(t.sku)">{{ t.name }} [{{ t.sku }}]</v-chip>
            </div>
            <EChart v-if="comparisonOption" :option="comparisonOption" height="260px" label="Quantités vendues par jour pour les références suivies" />
            <p v-else class="text-medium-emphasis text-body-2">Ajoutez une ou plusieurs références pour comparer leurs ventes.</p>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </template>
</template>
