<script setup>
import DOMPurify from 'dompurify'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, errorMessage } from '@/services/api'
import { dt, stripHtml } from '@/utils/format'
import { priceFormError } from '@/utils/rules'

const PAGE_SIZE = 20

const tab = ref('validated')
const search = ref('')
const appliedSearch = ref('')
const page = ref(1)
const result = ref({ products: [], total: 0, totalPages: 1, source: null })
const loading = ref(false)
const error = ref('')
const snackbar = reactive({ show: false, text: '', color: 'success' })

const pendingDialog = ref(null)
const deleteDialog = reactive({ product: null, loading: false, error: '' })
const priceDialog = reactive({ product: null, price: null, hasPromo: false, specialPrice: null, from: '', to: '', loading: false, error: '' })

const emptyText = computed(() => {
  if (appliedSearch.value) return `Aucun produit ne correspond à « ${appliedSearch.value} ».`
  return tab.value === 'validated'
    ? 'Aucun produit validé pour le moment. Soumettez votre premier article : il apparaîtra ici après validation.'
    : 'Aucun produit en attente de validation.'
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const url = tab.value === 'validated' ? '/api/magento/products' : '/api/magento/products/pending'
    const { data } = await api.get(url, { params: { page: page.value, pageSize: PAGE_SIZE, search: appliedSearch.value || undefined } })
    result.value = data
  } catch (e) {
    result.value = { products: [], total: 0, totalPages: 1 }
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

function runSearch() {
  appliedSearch.value = search.value.trim()
  page.value = 1
  load()
}

watch(tab, () => {
  search.value = ''
  appliedSearch.value = ''
  page.value = 1
  load()
})
watch(page, () => {
  window.scrollTo({ top: 0, behavior: 'smooth' })
  load()
})
onMounted(load)

const safeHtml = (html) => DOMPurify.sanitize(String(html || ''), { ALLOWED_TAGS: ['b', 'strong', 'i', 'em', 'br', 'p', 'ul', 'li'], ALLOWED_ATTR: [] })

function notify(text, color = 'success') {
  Object.assign(snackbar, { show: true, text, color })
}

async function openProduct(product) {
  if (tab.value === 'pending' || product.status === 2) {
    pendingDialog.value = product
    return
  }
  // Ouvre l'onglet tout de suite (sinon il est bloqué), puis charge l'adresse.
  const win = window.open('', '_blank')
  try {
    const { data } = await api.get('/api/magento/products/url-key', { params: { sku: product.sku } })
    if (data.url && win) win.location.href = data.url
    else {
      win?.close()
      notify('Adresse du produit non disponible', 'error')
    }
  } catch (e) {
    win?.close()
    notify(errorMessage(e, 'Impossible d\'ouvrir le produit'), 'error')
  }
}

function openPrice(product) {
  Object.assign(priceDialog, {
    product,
    price: product.price,
    hasPromo: Boolean(product.special_price),
    specialPrice: product.special_price,
    from: product.special_from_date?.slice(0, 10) || '',
    to: product.special_to_date?.slice(0, 10) || '',
    loading: false,
    error: '',
  })
}

const priceProblem = computed(() =>
  priceDialog.product
    ? priceFormError({ price: priceDialog.price, hasPromo: priceDialog.hasPromo, specialPrice: priceDialog.specialPrice, from: priceDialog.from, to: priceDialog.to }, priceDialog.product)
    : null,
)

async function savePrice() {
  if (priceProblem.value) return
  priceDialog.loading = true
  priceDialog.error = ''
  try {
    await api.put('/api/magento/product/price', {
      sku: priceDialog.product.sku,
      price: Number(priceDialog.price),
      special_price: priceDialog.hasPromo ? Number(priceDialog.specialPrice) : null,
      special_from_date: priceDialog.hasPromo && priceDialog.from ? priceDialog.from : null,
      special_to_date: priceDialog.hasPromo && priceDialog.to ? priceDialog.to : null,
    })
    Object.assign(priceDialog.product, {
      price: Number(priceDialog.price),
      special_price: priceDialog.hasPromo ? Number(priceDialog.specialPrice) : null,
      special_from_date: priceDialog.hasPromo ? priceDialog.from : null,
      special_to_date: priceDialog.hasPromo ? priceDialog.to : null,
    })
    priceDialog.product = null
    notify('Prix modifié avec succès')
  } catch (e) {
    priceDialog.error = errorMessage(e, 'Erreur lors de la mise à jour du prix.')
  } finally {
    priceDialog.loading = false
  }
}

async function confirmDelete() {
  deleteDialog.loading = true
  deleteDialog.error = ''
  try {
    await api.delete('/api/magento/product/delete', { data: { sku: deleteDialog.product.sku } })
    result.value.products = result.value.products.filter((p) => p.sku !== deleteDialog.product.sku)
    result.value.total -= 1
    deleteDialog.product = null
    notify('Produit supprimé avec succès')
  } catch (e) {
    deleteDialog.error = errorMessage(e, 'Erreur lors de la suppression.')
  } finally {
    deleteDialog.loading = false
  }
}
</script>

<template>
  <div class="d-flex flex-wrap align-center ga-3 mb-4">
    <div class="flex-grow-1">
      <h1 class="page-title">Mes produits</h1>
      <p class="page-subtitle">{{ result.total }} produit(s) {{ tab === 'validated' ? 'validé(s)' : 'en attente de validation' }}</p>
    </div>
    <v-btn color="primary" prepend-icon="mdi-plus" :to="{ name: 'add-product' }">Ajouter un produit</v-btn>
  </div>

  <v-card class="mb-4">
    <v-tabs v-model="tab" color="primary">
      <v-tab value="validated" prepend-icon="mdi-check-decagram-outline">Produits validés</v-tab>
      <v-tab value="pending" prepend-icon="mdi-timer-sand">Produits en attente</v-tab>
    </v-tabs>
    <v-divider />
    <v-card-text>
      <v-text-field
        v-model="search"
        label="Rechercher par nom ou SKU"
        prepend-inner-icon="mdi-magnify"
        clearable
        hide-details
        @keyup.enter="runSearch"
        @click:clear="search = ''; runSearch()"
      >
        <template #append><v-btn color="primary" variant="tonal" @click="runSearch">Rechercher</v-btn></template>
      </v-text-field>
    </v-card-text>
  </v-card>

  <v-alert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</v-alert>

  <div v-if="result.totalPages > 1" class="d-flex justify-center mb-2">
    <v-pagination v-model="page" :length="result.totalPages" :total-visible="7" density="comfortable" />
  </div>

  <v-row v-if="loading">
    <v-col v-for="n in 8" :key="n" cols="12" sm="6" md="4" xl="3"><v-skeleton-loader type="card" /></v-col>
  </v-row>

  <v-row v-else-if="result.products.length">
    <v-col v-for="product in result.products" :key="product.sku" cols="12" sm="6" md="4" xl="3">
      <v-card class="h-100 d-flex flex-column">
        <v-img :src="product.image || undefined" height="170" contain class="bg-grey-lighten-4" :alt="product.name">
          <template #error><div class="d-flex fill-height align-center justify-center"><v-icon size="48" color="grey">mdi-image-off-outline</v-icon></div></template>
        </v-img>
        <v-card-item>
          <v-card-title class="text-body-1 font-weight-medium clamp-2 text-wrap">
            <a href="#" class="text-decoration-none text-high-emphasis" @click.prevent="openProduct(product)">{{ product.name }}</a>
          </v-card-title>
          <v-card-subtitle>[{{ product.sku }}]</v-card-subtitle>
        </v-card-item>
        <v-card-text class="flex-grow-1">
          <!-- Description nettoyée (DOMPurify) : pas d'exécution de HTML arbitraire. -->
          <div class="text-body-2 text-medium-emphasis clamp-3" :title="stripHtml(product.short_description)" v-html="safeHtml(product.short_description)" />
          <div class="mt-3">
            <template v-if="product.special_price">
              <span class="text-h6 text-primary font-weight-bold">{{ dt(product.special_price) }}</span>
              <span class="text-body-2 text-medium-emphasis text-decoration-line-through ml-2">{{ dt(product.price) }}</span>
            </template>
            <span v-else class="text-h6 font-weight-bold">{{ dt(product.price) }}</span>
          </div>
        </v-card-text>
        <v-card-actions>
          <v-chip :color="product.status === 1 ? 'success' : 'warning'" size="small" variant="tonal">
            {{ product.status === 1 ? 'Validé' : 'En attente de validation' }}
          </v-chip>
          <v-spacer />
          <template v-if="tab === 'validated'">
            <v-btn icon="mdi-tag-edit-outline" size="small" variant="text" :aria-label="`Modifier le prix de ${product.name}`" @click="openPrice(product)" />
          </template>
          <v-btn icon="mdi-delete-outline" size="small" variant="text" color="error" :aria-label="`Supprimer ${product.name}`" @click="Object.assign(deleteDialog, { product, error: '' })" />
        </v-card-actions>
      </v-card>
    </v-col>
  </v-row>

  <v-card v-else class="pa-8 text-center">
    <v-icon size="48" color="grey">mdi-package-variant</v-icon>
    <p class="mt-3 mb-4">{{ emptyText }}</p>
    <v-btn v-if="!appliedSearch && tab === 'validated'" color="primary" :to="{ name: 'add-product' }">Soumettre un produit</v-btn>
  </v-card>

  <div v-if="result.totalPages > 1 && !loading" class="d-flex flex-column align-center mt-4">
    <v-pagination v-model="page" :length="result.totalPages" :total-visible="7" density="comfortable" />
    <span class="text-body-2 text-medium-emphasis">Page {{ page }} / {{ result.totalPages }}</span>
  </div>

  <!-- Produit en attente -->
  <v-dialog :model-value="!!pendingDialog" max-width="420" @update:model-value="pendingDialog = null">
    <v-card prepend-icon="mdi-timer-sand" title="Produit en attente de validation">
      <v-card-text>Le produit <strong>{{ pendingDialog?.name }}</strong> est en cours de validation. Il sera disponible sur le site une fois validé.</v-card-text>
      <v-card-actions><v-spacer /><v-btn @click="pendingDialog = null">Fermer</v-btn></v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Modification du prix -->
  <v-dialog :model-value="!!priceDialog.product" max-width="480" persistent>
    <v-card v-if="priceDialog.product" title="Modifier le prix" :subtitle="priceDialog.product.name">
      <v-card-text>
        <v-number-input v-model="priceDialog.price" label="Prix normal (DT)" :min="0" :step="1" :precision="3" control-variant="hidden" />
        <v-switch v-model="priceDialog.hasPromo" color="primary" label="Activer une promotion" hide-details class="mb-2" />
        <template v-if="priceDialog.hasPromo">
          <v-number-input v-model="priceDialog.specialPrice" label="Prix promotionnel (DT)" :min="0" :step="1" :precision="3" control-variant="hidden" />
          <v-row density="compact">
            <v-col cols="6"><v-text-field v-model="priceDialog.from" type="date" label="Début (optionnel)" /></v-col>
            <v-col cols="6"><v-text-field v-model="priceDialog.to" type="date" label="Fin (optionnel)" /></v-col>
          </v-row>
        </template>
        <v-alert v-if="priceProblem && priceProblem !== 'Aucune modification.'" type="warning" variant="tonal" density="compact">{{ priceProblem }}</v-alert>
        <v-alert v-if="priceDialog.error" type="error" variant="tonal" density="compact" class="mt-2">{{ priceDialog.error }}</v-alert>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn :disabled="priceDialog.loading" @click="priceDialog.product = null">Annuler</v-btn>
        <v-btn color="primary" variant="flat" :loading="priceDialog.loading" :disabled="!!priceProblem" @click="savePrice">Enregistrer</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Suppression -->
  <v-dialog :model-value="!!deleteDialog.product" max-width="440" persistent>
    <v-card v-if="deleteDialog.product" prepend-icon="mdi-alert-outline" title="Cette action est irréversible">
      <v-card-text>
        Vous êtes sur le point de supprimer le produit <strong>{{ deleteDialog.product.name }}</strong>.
        <v-alert v-if="deleteDialog.error" type="error" variant="tonal" density="compact" class="mt-3">{{ deleteDialog.error }}</v-alert>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn :disabled="deleteDialog.loading" @click="deleteDialog.product = null">Annuler</v-btn>
        <v-btn color="error" variant="flat" :loading="deleteDialog.loading" @click="confirmDelete">Oui, supprimer</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000">{{ snackbar.text }}</v-snackbar>
</template>
