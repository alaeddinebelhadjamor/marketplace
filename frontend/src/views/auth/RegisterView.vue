<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { errorMessage } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { GOVERNORATES } from '@/utils/governorates'
import { email, minLength, required } from '@/utils/rules'

const auth = useAuthStore()
const form = ref(null)
const valid = ref(false)
const loading = ref(false)
const success = ref(false)
const error = ref('')

const data = reactive({
  firstname: '', lastname: '', email: '', password: '', contact_number: '', shop_title: '',
  company: '', address: '', zipcode: '', governorate: null, has_patent: false, tax_id: '',
})

// Matricule fiscal obligatoire seulement si le vendeur déclare une patente.
const taxRules = computed(() => (data.has_patent ? [required('Le matricule fiscal')] : []))
watch(() => data.has_patent, (v) => { if (!v) data.tax_id = '' })

async function submit() {
  const result = await form.value.validate()
  if (!result.valid || success.value) return
  loading.value = true
  error.value = ''
  try {
    await auth.register({ ...data, email: data.email.trim(), has_patent: data.has_patent ? 1 : 0 })
    success.value = true
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

defineExpose({ data, valid })
</script>

<template>
  <v-container class="py-10">
    <v-row justify="center">
      <v-col cols="12" md="9" lg="7">
        <v-card elevation="3" class="pa-2">
          <v-card-item>
            <div class="text-overline text-primary">Mytek Marketplace</div>
            <v-card-title class="text-h5 px-0">Devenir vendeur partenaire</v-card-title>
            <v-card-subtitle class="px-0">Créez votre boutique et vendez sur la marketplace n°1 de la tech en Tunisie.</v-card-subtitle>
          </v-card-item>

          <v-card-text>
            <v-alert v-if="success" type="success" variant="tonal" title="Bienvenue chez Mytek !" data-test="success">
              Votre demande a été enregistrée. Notre équipe valide votre dossier sous 48 h ; vous pourrez ensuite vous connecter.
              <div class="mt-3"><v-btn color="success" variant="flat" :to="{ name: 'login' }">Aller à la connexion</v-btn></div>
            </v-alert>

            <v-form v-else ref="form" v-model="valid" @submit.prevent="submit">
              <v-row density="compact">
                <v-col cols="12" sm="6"><v-text-field v-model="data.firstname" label="Prénom *" autocomplete="given-name" :rules="[required('Le prénom')]" /></v-col>
                <v-col cols="12" sm="6"><v-text-field v-model="data.lastname" label="Nom *" autocomplete="family-name" :rules="[required('Le nom')]" /></v-col>
                <v-col cols="12" sm="6"><v-text-field v-model="data.email" label="Email professionnel *" type="email" autocomplete="email" :rules="[required('L\'email'), email]" data-test="email" /></v-col>
                <v-col cols="12" sm="6"><v-text-field v-model="data.password" label="Mot de passe *" type="password" autocomplete="new-password" hint="6 caractères minimum" :rules="[required('Le mot de passe'), minLength(6, 'Le mot de passe')]" /></v-col>
                <v-col cols="12" sm="6"><v-text-field v-model="data.contact_number" label="Téléphone *" autocomplete="tel" placeholder="20 123 456" :rules="[required('Le numéro')]" /></v-col>
                <v-col cols="12" sm="6"><v-text-field v-model="data.shop_title" label="Nom du magasin (enseigne) *" hint="Le nom affiché sur le site, il sert aussi d'identifiant" :rules="[required('Le nom du magasin')]" /></v-col>
                <v-col cols="12" sm="6"><v-text-field v-model="data.company" label="Société" autocomplete="organization" /></v-col>
                <v-col cols="12" sm="6"><v-text-field v-model="data.address" label="Adresse complète *" autocomplete="street-address" :rules="[required('L\'adresse')]" /></v-col>
                <v-col cols="12" sm="6"><v-text-field v-model="data.zipcode" label="Code postal *" autocomplete="postal-code" :rules="[required('Le code postal')]" /></v-col>
                <v-col cols="12" sm="6"><v-select v-model="data.governorate" :items="GOVERNORATES" label="Gouvernorat *" :rules="[required('Le gouvernorat')]" /></v-col>
                <v-col cols="12"><v-checkbox v-model="data.has_patent" label="Je possède une patente commerciale" hide-details data-test="patent" /></v-col>
                <v-col v-if="data.has_patent" cols="12" sm="6">
                  <v-text-field v-model="data.tax_id" label="Matricule fiscal *" :rules="taxRules" data-test="tax-id" />
                </v-col>
              </v-row>

              <v-alert v-if="error" type="error" variant="tonal" density="compact" class="my-3" data-test="error">{{ error }}</v-alert>

              <v-btn type="submit" color="primary" size="large" block class="mt-2" :loading="loading" :disabled="!valid" data-test="submit">
                Créer mon compte vendeur
              </v-btn>
            </v-form>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>
