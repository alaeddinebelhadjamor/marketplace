<script setup>
import { ref } from 'vue'
import { api, errorMessage } from '@/services/api'
import { email, required } from '@/utils/rules'

const form = ref(null)
const address = ref('')
const loading = ref(false)
const message = ref('')
const error = ref('')

async function submit() {
  const { valid } = await form.value.validate()
  if (!valid) return
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.post('/api/auth/forgot-password', { email: address.value.trim() })
    message.value = data.message
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
          <v-card-title class="text-h6">Mot de passe oublié</v-card-title>
          <v-card-text>
            <v-alert v-if="message" type="success" variant="tonal">{{ message }}</v-alert>
            <v-form v-else ref="form" @submit.prevent="submit">
              <p class="text-body-2 mb-4">Indiquez l'email de votre compte vendeur : nous vous enverrons un lien pour choisir un nouveau mot de passe.</p>
              <v-text-field v-model="address" label="Email" type="email" autocomplete="email" :rules="[required('L\'email'), email]" />
              <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">{{ error }}</v-alert>
              <v-btn type="submit" color="primary" block :loading="loading">Envoyer le lien</v-btn>
            </v-form>
          </v-card-text>
          <v-card-actions class="justify-center"><v-btn variant="text" :to="{ name: 'login' }">Retour à la connexion</v-btn></v-card-actions>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>
