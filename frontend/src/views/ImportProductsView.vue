<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { api, errorMessage } from '@/services/api'
import { downloadFile } from '@/services/files'
import { dateTime } from '@/utils/format'

const file = ref(null)
const uploading = ref(false)
const error = ref('')
const imports = ref([])
let timer = null

const STATUS = {
  queued: { label: 'En file d\'attente', color: 'grey' },
  processing: { label: 'En cours', color: 'info' },
  done: { label: 'Terminé', color: 'success' },
  failed: { label: 'Échec', color: 'error' },
}

async function load() {
  const { data } = await api.get('/api/magento/products/imports')
  imports.value = data
  // Tant qu'un import tourne, on rafraîchit toutes les 3 secondes.
  const running = data.some((i) => ['queued', 'processing'].includes(i.status))
  clearTimeout(timer)
  if (running) timer = setTimeout(() => load().catch(() => {}), 3000)
}

async function upload() {
  const f = Array.isArray(file.value) ? file.value[0] : file.value
  if (!f) return
  uploading.value = true
  error.value = ''
  try {
    const body = new FormData()
    body.append('file', f)
    await api.post('/api/magento/products/imports', body)
    file.value = null
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    uploading.value = false
  }
}

onMounted(() => load().catch((e) => (error.value = errorMessage(e))))
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <div class="mb-4">
    <h1 class="page-title">Import de produits en masse</h1>
    <p class="page-subtitle">Chaque ligne est contrôlée comme un ajout manuel ; les lignes valides sont soumises à validation.</p>
  </div>

  <v-row>
    <v-col cols="12" lg="5">
      <v-card>
        <v-card-title class="text-subtitle-1">1. Préparer le fichier</v-card-title>
        <v-card-text>
          Colonnes : <code>sku</code>, <code>name</code>, <code>price</code> (obligatoires), <code>weight</code>, <code>qty</code>,
          <code>short_description</code>, <code>description</code>. 500 lignes au maximum, format .xlsx, .xls ou .csv.
        </v-card-text>
        <v-card-actions>
          <v-btn prepend-icon="mdi-download" @click="downloadFile('/api/magento/products/imports/template', {}, 'modele_import_produits.xlsx')">Télécharger le modèle</v-btn>
        </v-card-actions>
        <v-divider />
        <v-card-title class="text-subtitle-1">2. Envoyer le fichier</v-card-title>
        <v-card-text>
          <v-file-input v-model="file" label="Fichier de produits" accept=".xlsx,.xls,.csv" prepend-icon="mdi-file-excel-outline" show-size />
          <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">{{ error }}</v-alert>
          <v-btn color="primary" :loading="uploading" :disabled="!file" prepend-icon="mdi-upload" @click="upload">Lancer l'import</v-btn>
        </v-card-text>
      </v-card>
    </v-col>

    <v-col cols="12" lg="7">
      <v-card>
        <v-card-title class="text-subtitle-1">Derniers imports</v-card-title>
        <v-card-text v-if="!imports.length" class="text-medium-emphasis">Aucun import pour le moment.</v-card-text>
        <v-expansion-panels v-else variant="accordion">
          <v-expansion-panel v-for="imp in imports" :key="imp.id">
            <v-expansion-panel-title>
              <div class="d-flex flex-wrap align-center ga-2 w-100">
                <strong class="mr-2">{{ imp.original_name }}</strong>
                <v-chip :color="STATUS[imp.status].color" size="small" variant="tonal">{{ STATUS[imp.status].label }}</v-chip>
                <v-spacer />
                <span class="text-body-2 text-medium-emphasis">{{ dateTime(imp.created_at) }}</span>
              </div>
            </v-expansion-panel-title>
            <v-expansion-panel-text>
              <v-progress-linear v-if="['queued', 'processing'].includes(imp.status)" indeterminate color="primary" class="mb-3" />
              <p v-if="imp.failure_reason" class="text-error">{{ imp.failure_reason }}</p>
              <p v-else>
                {{ imp.total_rows }} ligne(s) : <strong class="text-success">{{ imp.success_rows }} soumise(s)</strong>,
                <strong :class="imp.failed_rows ? 'text-error' : ''">{{ imp.failed_rows }} en erreur</strong>.
              </p>
              <v-table v-if="imp.errors.length" density="compact" class="my-2">
                <thead><tr><th>Ligne</th><th>SKU</th><th>Erreur</th></tr></thead>
                <tbody>
                  <tr v-for="e in imp.errors" :key="e.row"><td>{{ e.row }}</td><td>{{ e.sku }}</td><td>{{ e.message }}</td></tr>
                </tbody>
              </v-table>
              <v-btn v-if="imp.failed_rows" size="small" prepend-icon="mdi-download" @click="downloadFile(`/api/magento/products/imports/${imp.id}/report`, {}, `rapport_import_${imp.id}.xlsx`)">
                Rapport complet des erreurs
              </v-btn>
            </v-expansion-panel-text>
          </v-expansion-panel>
        </v-expansion-panels>
      </v-card>
    </v-col>
  </v-row>
</template>
