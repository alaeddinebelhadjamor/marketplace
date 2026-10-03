<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { errorMessage } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { required } from '@/utils/rules'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const step = ref('credentials') // credentials | two-factor
const form = ref(null)
const identifier = ref('')
const password = ref('')
const code = ref('')
const useRecovery = ref(false)
const showPassword = ref(false)
const loading = ref(false)
const error = ref(route.query.expired ? 'Votre session a expiré. Reconnectez-vous.' : '')

function goToSpace() {
  const target = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/dashboard') ? route.query.redirect : '/dashboard'
  router.push(target)
}

async function submit() {
  const { valid } = await form.value.validate()
  if (!valid) return
  loading.value = true
  error.value = ''
  try {
    const result = await auth.login(identifier.value.trim(), password.value)
    if (result.twoFactor) {
      step.value = 'two-factor'
      password.value = ''
    } else {
      goToSpace()
    }
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de se connecter au serveur.')
  } finally {
    loading.value = false
  }
}

async function submitCode() {
  if (!code.value.trim()) return
  loading.value = true
  error.value = ''
  try {
    await auth.verifyTwoFactor(useRecovery.value ? { recoveryCode: code.value.trim() } : { code: code.value.trim() })
    goToSpace()
  } catch (e) {
    error.value = errorMessage(e, 'Code invalide.')
    if (e.response?.status === 401 && /expirée/.test(error.value)) step.value = 'credentials'
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
          <v-card-item>
            <div class="text-overline text-primary">Mytek Marketplace</div>
            <v-card-title class="text-h5 px-0">Espace vendeur</v-card-title>
            <v-card-subtitle class="px-0">
              {{ step === 'credentials' ? 'Connectez-vous pour gérer votre boutique' : 'Double authentification' }}
            </v-card-subtitle>
          </v-card-item>

          <v-card-text>
            <v-alert v-if="error" type="error" variant="tonal" class="mb-4" density="compact" data-test="error">{{ error }}</v-alert>

            <v-form v-if="step === 'credentials'" ref="form" @submit.prevent="submit">
              <v-text-field
                v-model="identifier"
                label="Identifiant"
                placeholder="Email ou nom du magasin"
                prepend-inner-icon="mdi-account-outline"
                autocomplete="username"
                :rules="[required('L\'identifiant')]"
                data-test="identifier"
              />
              <v-text-field
                v-model="password"
                label="Mot de passe"
                :type="showPassword ? 'text' : 'password'"
                prepend-inner-icon="mdi-lock-outline"
                :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
                autocomplete="current-password"
                :rules="[required('Le mot de passe')]"
                data-test="password"
                @click:append-inner="showPassword = !showPassword"
              />
              <div class="text-right mb-4">
                <router-link :to="{ name: 'forgot-password' }" class="text-body-2">Mot de passe oublié ?</router-link>
              </div>
              <v-btn type="submit" color="primary" block size="large" :loading="loading" data-test="submit">Accéder à mon espace</v-btn>
            </v-form>

            <v-form v-else @submit.prevent="submitCode">
              <p class="text-body-2 mb-4">
                {{ useRecovery ? 'Saisissez un de vos codes de secours.' : 'Saisissez le code à 6 chiffres affiché par votre application d\'authentification.' }}
              </p>
              <v-text-field
                v-model="code"
                :label="useRecovery ? 'Code de secours' : 'Code de vérification'"
                :inputmode="useRecovery ? 'text' : 'numeric'"
                autocomplete="one-time-code"
                prepend-inner-icon="mdi-shield-key-outline"
                autofocus
                data-test="code"
              />
              <v-btn type="submit" color="primary" block size="large" :loading="loading" :disabled="!code.trim()" data-test="verify">Vérifier</v-btn>
              <v-btn variant="text" block class="mt-2" @click="useRecovery = !useRecovery; code = ''">
                {{ useRecovery ? 'Utiliser un code de l\'application' : 'Utiliser un code de secours' }}
              </v-btn>
            </v-form>
          </v-card-text>

          <v-card-actions class="justify-center">
            <span class="text-body-2">Pas encore de compte ?</span>
            <v-btn variant="text" color="primary" :to="{ name: 'register' }">Devenir vendeur</v-btn>
          </v-card-actions>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>
