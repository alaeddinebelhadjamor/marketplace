<script setup>
import { reactive, ref } from 'vue'
import { api, errorMessage } from '@/services/api'
import { toBase64 } from '@/services/files'
import { positive, required, sku } from '@/utils/rules'

const ACCEPTED = 'image/jpeg,image/png,image/gif,image/webp'
const MAX_MB = 5

const form = ref(null)
const loading = ref(false)
const success = ref('')
const error = ref('')
const data = reactive({ sku: '', name: '', price: null, weight: null, qty: 10, short_description: '', description: '' })
const mainImage = ref(null)
const extraImages = ref([])

const imageRule = (files) => {
  const list = Array.isArray(files) ? files : files ? [files] : []
  if (list.some((f) => f.size > MAX_MB * 1024 * 1024)) return `Chaque image doit faire moins de ${MAX_MB} Mo.`
  return true
}

async function mediaEntry(file, label) {
  return { label, content: { base64_encoded_data: await toBase64(file), type: file.type || 'image/jpeg', name: file.name } }
}

async function submit() {
  const { valid } = await form.value.validate()
  if (!valid) return
  loading.value = true
  error.value = ''
  success.value = ''
  try {
    const main = Array.isArray(mainImage.value) ? mainImage.value[0] : mainImage.value
    const media = [await mediaEntry(main, 'Image principale')]
    for (const f of extraImages.value || []) media.push(await mediaEntry(f, f.name))

    await api.post('/api/magento/product/add', {
      product: {
        sku: data.sku.trim(),
        name: data.name.trim(),
        price: Number(data.price),
        weight: data.weight ? Number(data.weight) : null,
        extension_attributes: { stock_item: { qty: Number(data.qty) || 0 } },
        custom_attributes: [
          { attribute_code: 'short_description', value: data.short_description },
          { attribute_code: 'description', value: data.description },
        ],
        media_gallery_entries: media,
      },
    })
    success.value = `Produit « ${data.name} » soumis : il est en attente de validation par l'équipe Mytek.`
    form.value.reset()
    mainImage.value = null
    extraImages.value = []
  } catch (e) {
    error.value = errorMessage(e, 'Erreur serveur inconnue')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="mb-4">
    <h1 class="page-title">Ajouter un produit</h1>
    <p class="page-subtitle">Le produit est créé désactivé, puis publié sur mytek.tn après validation par un intégrateur.</p>
  </div>

  <v-row>
    <v-col cols="12" lg="8">
      <v-card>
        <v-card-text>
          <v-alert v-if="success" type="success" variant="tonal" closable class="mb-4" @click:close="success = ''">{{ success }}</v-alert>
          <v-form ref="form" @submit.prevent="submit">
            <v-row density="compact">
              <v-col cols="12" sm="6"><v-text-field v-model="data.sku" label="SKU *" placeholder="ex. SKU-12345" :rules="[required('Le SKU'), sku]" data-test="sku" /></v-col>
              <v-col cols="12" sm="6"><v-text-field v-model="data.name" label="Nom *" :rules="[required('Le nom'), (v) => !v || v.trim().length >= 2 || 'Au moins 2 caractères.']" counter="255" maxlength="255" /></v-col>
              <v-col cols="12" sm="4"><v-number-input v-model="data.price" label="Prix (DT) *" :min="0" :precision="3" control-variant="hidden" :rules="[required('Le prix'), positive()]" /></v-col>
              <v-col cols="12" sm="4"><v-number-input v-model="data.weight" label="Poids (kg) *" :min="0" :precision="3" control-variant="hidden" :rules="[required('Le poids')]" /></v-col>
              <v-col cols="12" sm="4"><v-number-input v-model="data.qty" label="Stock disponible" :min="0" :precision="0" control-variant="stacked" /></v-col>
              <v-col cols="12"><v-textarea v-model="data.short_description" label="Description courte" rows="2" auto-grow /></v-col>
              <v-col cols="12"><v-textarea v-model="data.description" label="Description complète" rows="4" auto-grow /></v-col>
              <v-col cols="12" sm="6">
                <v-file-input v-model="mainImage" label="Image principale *" :accept="ACCEPTED" prepend-icon="mdi-image" show-size :rules="[(v) => (v && (!Array.isArray(v) || v.length)) || 'L\'image principale est obligatoire.', imageRule]" />
              </v-col>
              <v-col cols="12" sm="6">
                <v-file-input v-model="extraImages" label="Images secondaires" :accept="ACCEPTED" prepend-icon="mdi-image-multiple" multiple chips show-size :rules="[imageRule]" />
              </v-col>
            </v-row>
            <v-alert v-if="error" type="error" variant="tonal" density="compact" class="my-3" data-test="error">{{ error }}</v-alert>
            <v-btn type="submit" color="primary" size="large" :loading="loading" prepend-icon="mdi-send">Soumettre le produit</v-btn>
          </v-form>
        </v-card-text>
      </v-card>
    </v-col>
    <v-col cols="12" lg="4">
      <v-card variant="tonal" color="secondary">
        <v-card-title class="text-subtitle-1">Beaucoup de produits ?</v-card-title>
        <v-card-text>Importez-les en une fois depuis un fichier Excel ou CSV, avec un rapport des lignes en erreur.</v-card-text>
        <v-card-actions><v-btn :to="{ name: 'import' }" prepend-icon="mdi-file-upload-outline">Import en masse</v-btn></v-card-actions>
      </v-card>
    </v-col>
  </v-row>
</template>
