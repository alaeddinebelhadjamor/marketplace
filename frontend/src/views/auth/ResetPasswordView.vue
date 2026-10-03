<script setup>
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, errorMessage } from '@/services/api'
import { minLength, required } from '@/utils/rules'

const route = useRoute()
const form = ref(null)
const password = ref('')
const confirmation = ref('')
const loading = ref(false)
const done = ref(false)
const error = ref('')
const token = String(route.query.token || '')
const email = String(route.query.email || '')

const sameAsPassword = (v) => v === password.value || 'Les mots de passe ne correspondent pas.'

async function submit() {
  const { valid } = await form.value.validate()
  if (!valid) return
  loading.value = true
  error.value = ''
  try {
    await api.post('/api/auth/reset-password', { token, email, password: password.value, password_confirmation: confirmation.value })
    done.value = true
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <v-container class="py-12">
    <v-row justify="center">
      <v-col cols="12" sm="9" md="6" lg="4">
        <v-card elevation="3" class="pa-2">
          <v-card-title class="text-h6">Nouveau mot de passe</v-card-title>
          <v-card-text>
            <v-alert v-if="!token || !email" type="warning" variant="tonal">Lien incomplet : utilisez le lien reçu par email.</v-alert>
            <v-alert v-else-if="done" type="success" variant="tonal">
              Mot de passe réinitialisé.
              <div class="mt-3"><v-btn color="success" variant="flat" :to="{ name: 'login' }">Se connecter</v-btn></div>
            </v-alert>
            <v-form v-else ref="form" @submit.prevent="submit">
              <p class="text-body-2 mb-4">Compte : <strong>{{ email }}</strong></p>
              <v-text-field v-model="password" label="Nouveau mot de passe" type="password" autocomplete="new-password" :rules="[required('Le mot de passe'), minLength(6, 'Le mot de passe')]" />
              <v-text-field v-model="confirmation" label="Confirmation" type="password" autocomplete="new-password" :rules="[required('La confirmation'), sameAsPassword]" />
              <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">{{ error }}</v-alert>
              <v-btn type="submit" color="primary" block :loading="loading">Enregistrer</v-btn>
            </v-form>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>
