<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api, errorMessage } from '@/services/api'
import { downloadFile } from '@/services/files'
import { date, dt, num } from '@/utils/format'
import { dateRangeError } from '@/utils/rules'

const orders = ref([])
const loading = ref(false)
const exporting = ref(false)
const error = ref('')
const sku = ref('')
const from = ref('')
const to = ref('')
const selected = ref(null)

const rangeError = computed(() => dateRangeError(from.value, to.value))

async function load() {
  if (rangeError.value) {
    orders.value = []
    return
  }
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/api/orders', { params: { from: from.value || undefined, to: to.value || undefined } })
    orders.value = data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

watch([from, to], load)
onMounted(load)

// Filtre SKU instantané côté client, regroupement par référence (comme la v1).
const filtered = computed(() => {
  const q = sku.value.trim().toLowerCase()
  return q ? orders.value.filter((o) => o.sku.toLowerCase().includes(q)) : orders.value
})

const grouped = computed(() => {
  const map = new Map()
  for (const o of filtered.value) {
    const g = map.get(o.sku) || { sku: o.sku, product_name: o.product_name, total_qty: 0, total: 0, orders: [] }
    g.total_qty += o.qty
    g.total += o.qty * Number(o.price)
    g.orders.push(o)
    map.set(o.sku, g)
  }
  return [...map.values()].sort((a, b) => b.total - a.total)
})

const totals = computed(() => ({
  qty: grouped.value.reduce((s, g) => s + g.total_qty, 0),
  amount: grouped.value.reduce((s, g) => s + g.total, 0),
  orders: new Set(filtered.value.map((o) => o.order_id)).size,
}))

const headers = [
  { title: 'SKU', key: 'sku' },
  { title: 'Produit', key: 'product_name' },
  { title: 'Qté totale', key: 'total_qty', align: 'end' },
  { title: 'Prix moyen', key: 'avg', align: 'end', sortable: false },
  { title: 'Total', key: 'total', align: 'end' },
]

async function exportExcel() {
  exporting.value = true
  try {
    await downloadFile('/api/orders/export', { from: from.value || undefined, to: to.value || undefined, sku: sku.value || undefined }, 'commandes.xlsx')
  } catch (e) {
    error.value = errorMessage(e, 'Export impossible.')
  } finally {
    exporting.value = false
  }
}
</script>

<template>
  <div class="d-flex flex-wrap align-center ga-3 mb-4">
    <div class="flex-grow-1">
      <h1 class="page-title">Mes ventes</h1>
      <p class="page-subtitle">{{ num(totals.orders) }} commande(s), {{ num(totals.qty) }} article(s), {{ dt(totals.amount) }}</p>
    </div>
    <v-btn color="success" prepend-icon="mdi-microsoft-excel" :loading="exporting" :disabled="!grouped.length" @click="exportExcel">Exporter Excel</v-btn>
  </div>

  <v-card class="mb-4">
    <v-card-text>
      <v-row density="compact">
        <v-col cols="12" md="4"><v-text-field v-model="sku" label="Recherche par SKU" prepend-inner-icon="mdi-magnify" clearable hide-details data-test="sku" /></v-col>
        <v-col cols="6" md="3"><v-text-field v-model="from" type="date" label="Commandes du" hide-details data-test="from" /></v-col>
        <v-col cols="6" md="3"><v-text-field v-model="to" type="date" label="au" hide-details data-test="to" /></v-col>
        <v-col cols="12" md="2" class="d-flex align-center"><v-btn variant="text" @click="sku = ''; from = ''; to = ''">Réinitialiser</v-btn></v-col>
      </v-row>
      <v-alert v-if="rangeError" type="warning" variant="tonal" density="compact" class="mt-3" data-test="range-error">{{ rangeError }}</v-alert>
    </v-card-text>
  </v-card>

  <v-alert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</v-alert>

  <v-card>
    <v-data-table
      :headers="headers"
      :items="grouped"
      :loading="loading"
      item-value="sku"
      no-data-text="Aucune vente trouvée."
      loading-text="Chargement des ventes…"
      hover
      @click:row="(_, { item }) => (selected = item)"
    >
      <template #item.avg="{ item }">{{ dt(item.total / item.total_qty) }}</template>
      <template #item.total="{ item }"><strong>{{ dt(item.total) }}</strong></template>
    </v-data-table>
  </v-card>

  <v-dialog :model-value="!!selected" max-width="760" @update:model-value="selected = null">
    <v-card v-if="selected" :title="`Détail des commandes — ${selected.sku}`" :subtitle="selected.product_name">
      <v-table density="compact">
        <thead><tr><th>N° commande</th><th class="text-right">Qté</th><th class="text-right">Prix unit.</th><th class="text-right">Total</th><th>Date</th></tr></thead>
        <tbody>
          <tr v-for="o in selected.orders" :key="o.order_id + o.sku">
            <td>{{ o.order_id }}</td>
            <td class="text-right">{{ o.qty }}</td>
            <td class="text-right">{{ dt(o.price) }}</td>
            <td class="text-right">{{ dt(o.qty * o.price) }}</td>
            <td>{{ date(o.effective_at) }}</td>
          </tr>
        </tbody>
      </v-table>
      <v-card-actions><v-spacer /><v-btn @click="selected = null">Fermer</v-btn></v-card-actions>
    </v-card>
  </v-dialog>
</template>
