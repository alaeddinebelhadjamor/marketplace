<script setup>
import { onMounted, ref } from 'vue'
import { api, errorMessage } from '@/services/api'
import { downloadFile } from '@/services/files'
import { date, dt, num } from '@/utils/format'

const statements = ref([])
const rate = ref(null)
const loading = ref(true)
const error = ref('')
const downloading = ref(null)

const monthLabel = (period) => {
  const [y, m] = period.split('-').map(Number)
  const label = new Date(y, m - 1, 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
  return label.charAt(0).toUpperCase() + label.slice(1)
}

async function load() {
  try {
    const { data } = await api.get('/api/statements')
    statements.value = data.statements
    rate.value = data.commission_rate
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

async function download(period) {
  downloading.value = period
  try {
    await downloadFile(`/api/statements/${period}/pdf`, {}, `releve_${period}.pdf`)
  } catch (e) {
    error.value = errorMessage(e, 'Téléchargement impossible.')
  } finally {
    downloading.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="mb-4">
    <h1 class="page-title">Relevés de paiement</h1>
    <p class="page-subtitle">
      Un relevé par mois : chiffre d'affaires brut, commission Mytek<span v-if="rate !== null"> ({{ Math.round(rate * 1000) / 10 }} %)</span> et net à vous verser.
    </p>
  </div>

  <v-alert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</v-alert>

  <v-card>
    <v-table v-if="statements.length">
      <thead>
        <tr>
          <th>Mois</th><th class="text-right">Commandes</th><th class="text-right">CA brut</th>
          <th class="text-right">Commission</th><th class="text-right">Net à verser</th><th>Statut</th><th />
        </tr>
      </thead>
      <tbody>
        <tr v-for="s in statements" :key="s.period">
          <td>{{ monthLabel(s.period) }}</td>
          <td class="text-right">{{ num(s.orders_count) }}</td>
          <td class="text-right">{{ dt(s.gross) }}</td>
          <td class="text-right">− {{ dt(s.commission) }}</td>
          <td class="text-right"><strong>{{ dt(s.net) }}</strong></td>
          <td>
            <v-chip :color="s.status === 'paid' ? 'success' : 'warning'" size="small" variant="tonal">
              {{ s.status === 'paid' ? `Payé le ${date(s.paid_at)}` : 'En attente' }}
            </v-chip>
          </td>
          <td class="text-right">
            <v-btn size="small" variant="text" prepend-icon="mdi-file-pdf-box" :loading="downloading === s.period" @click="download(s.period)">PDF</v-btn>
          </td>
        </tr>
      </tbody>
    </v-table>
    <v-card-text v-else-if="!loading" class="text-center text-medium-emphasis py-8">
      <v-icon size="40">mdi-file-document-outline</v-icon>
      <p class="mt-2">Aucun relevé pour le moment. Les relevés sont générés le 1er de chaque mois pour le mois écoulé.</p>
    </v-card-text>
    <v-skeleton-loader v-else type="table" />
  </v-card>
</template>
