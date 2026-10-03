<script setup>
import DOMPurify from 'dompurify'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, errorMessage } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { dateTime } from '@/utils/format'
import { minLength, required } from '@/utils/rules'

const auth = useAuthStore()
const tab = ref('profile')
const seller = computed(() => auth.seller || {})

// --- Mot de passe ---------------------------------------------------------
const pwdForm = ref(null)
const pwd = reactive({ current: '', next: '', confirm: '', loading: false, success: '', error: '' })
const matches = (v) => v === pwd.next || 'Les mots de passe ne correspondent pas.'

async function changePassword() {
  const { valid } = await pwdForm.value.validate()
  if (!valid) return
  Object.assign(pwd, { loading: true, success: '', error: '' })
  try {
    const { data } = await api.put('/api/profile/change-password', { currentPassword: pwd.current, newPassword: pwd.next })
    pwd.success = data.message
    pwdForm.value.reset()
  } catch (e) {
    pwd.error = errorMessage(e, 'Erreur lors du changement.')
  } finally {
    pwd.loading = false
  }
}

// --- Double authentification ---------------------------------------------
const tf = reactive({ status: null, password: '', setup: null, code: '', codes: null, loading: false, error: '', success: '' })
const qr = computed(() => (tf.setup ? DOMPurify.sanitize(tf.setup.qr_svg, { USE_PROFILES: { svg: true } }) : ''))

async function loadTwoFactor() {
  const { data } = await api.get('/api/profile/two-factor')
  tf.status = data
}

async function tfAction(fn) {
  Object.assign(tf, { loading: true, error: '', success: '' })
  try {
    await fn()
  } catch (e) {
    tf.error = errorMessage(e)
  } finally {
    tf.loading = false
  }
}

const startEnable = () => tfAction(async () => {
  const { data } = await api.post('/api/profile/two-factor', { password: tf.password })
  tf.setup = data
  tf.codes = data.recovery_codes
  tf.password = ''
})

const confirmEnable = () => tfAction(async () => {
  await api.post('/api/profile/two-factor/confirm', { code: tf.code })
  tf.setup = null
  tf.code = ''
  tf.success = 'Double authentification activée. Conservez vos codes de secours en lieu sûr.'
  await loadTwoFactor()
  await auth.afterLogin()
})

const disable = () => tfAction(async () => {
  await api.delete('/api/profile/two-factor', { data: { password: tf.password } })
  Object.assign(tf, { password: '', codes: null, success: 'Double authentification désactivée.' })
  await loadTwoFactor()
  await auth.afterLogin()
})

const regenerate = () => tfAction(async () => {
  const { data } = await api.post('/api/profile/two-factor/recovery-codes', { password: tf.password })
  Object.assign(tf, { password: '', codes: data.recovery_codes, success: 'Nouveaux codes de secours générés.' })
  await loadTwoFactor()
})

// --- Journal d'activité -------------------------------------------------
const activity = ref([])
const EVENT_ICONS = {
  login: 'mdi-login', logout: 'mdi-logout', login_failed: 'mdi-alert-circle-outline', password_changed: 'mdi-key-change',
  password_reset: 'mdi-lock-reset', two_factor_enabled: 'mdi-shield-check', two_factor_disabled: 'mdi-shield-off-outline',
  product_price_updated: 'mdi-tag-edit-outline', product_deleted: 'mdi-delete-outline', product_submitted: 'mdi-package-variant-plus',
  reclamation_resolved: 'mdi-check-circle-outline', products_import_started: 'mdi-file-upload-outline',
}

watch(tab, async (t) => {
  if (t === 'security' && !tf.status) await loadTwoFactor().catch((e) => (tf.error = errorMessage(e)))
  if (t === 'activity') activity.value = (await api.get('/api/profile/activity')).data
})
onMounted(() => auth.afterLogin().catch(() => {}))

const profileRows = computed(() => [
  ['Prénom', seller.value.firstname], ['Nom', seller.value.lastname], ['Email', seller.value.email],
  ['Téléphone', seller.value.contact_number], ['Magasin', seller.value.shop_title], ['Société', seller.value.company],
  ['Adresse', seller.value.address], ['Gouvernorat', seller.value.governorate], ['Code postal', seller.value.zipcode],
  ['Patente', seller.value.has_patent ? 'Oui' : 'Non'], ...(seller.value.has_patent ? [['Matricule fiscal', seller.value.tax_id]] : []),
])
</script>

<template>
  <div class="mb-4">
    <h1 class="page-title">Mon compte</h1>
    <p class="page-subtitle">{{ seller.shop_title }} · {{ seller.email }}</p>
  </div>

  <v-card>
    <v-tabs v-model="tab" color="primary" show-arrows>
      <v-tab value="profile" prepend-icon="mdi-account-outline">Profil</v-tab>
      <v-tab value="password" prepend-icon="mdi-key-outline">Mot de passe</v-tab>
      <v-tab value="security" prepend-icon="mdi-shield-lock-outline">Double authentification</v-tab>
      <v-tab value="activity" prepend-icon="mdi-history">Activité</v-tab>
    </v-tabs>
    <v-divider />

    <v-window v-model="tab">
      <v-window-item value="profile">
        <v-table density="comfortable">
          <tbody>
            <tr v-for="[label, value] in profileRows" :key="label">
              <th class="text-medium-emphasis" style="width: 220px">{{ label }}</th>
              <td>{{ value || '—' }}</td>
            </tr>
          </tbody>
        </v-table>
        <v-card-text class="text-body-2 text-medium-emphasis">
          Pour modifier ces informations, contactez le support Mytek depuis le centre de support.
        </v-card-text>
      </v-window-item>

      <v-window-item value="password">
        <v-card-text style="max-width: 520px">
          <v-form ref="pwdForm" @submit.prevent="changePassword">
            <v-text-field v-model="pwd.current" label="Mot de passe actuel" type="password" autocomplete="current-password" :rules="[required('Le mot de passe actuel')]" />
            <v-text-field v-model="pwd.next" label="Nouveau mot de passe" type="password" autocomplete="new-password" :rules="[required('Le nouveau mot de passe'), minLength(6, 'Le mot de passe')]" />
            <v-text-field v-model="pwd.confirm" label="Confirmer le nouveau mot de passe" type="password" autocomplete="new-password" :rules="[required('La confirmation'), matches]" />
            <v-alert v-if="pwd.success" type="success" variant="tonal" density="compact" class="mb-3">{{ pwd.success }}</v-alert>
            <v-alert v-if="pwd.error" type="error" variant="tonal" density="compact" class="mb-3">{{ pwd.error }}</v-alert>
            <v-btn type="submit" color="primary" :loading="pwd.loading">Modifier le mot de passe</v-btn>
          </v-form>
        </v-card-text>
      </v-window-item>

      <v-window-item value="security">
        <v-card-text style="max-width: 640px">
          <v-alert v-if="tf.success" type="success" variant="tonal" density="compact" class="mb-3">{{ tf.success }}</v-alert>
          <v-alert v-if="tf.error" type="error" variant="tonal" density="compact" class="mb-3">{{ tf.error }}</v-alert>

          <template v-if="tf.setup">
            <p class="mb-3">1. Scannez ce QR code avec votre application d'authentification (Google Authenticator, Microsoft Authenticator…).</p>
            <div class="d-flex flex-wrap ga-6 align-center mb-4">
              <div class="bg-white pa-2 rounded border" v-html="qr" />
              <div>
                <div class="text-caption text-medium-emphasis">Ou saisissez la clé :</div>
                <code class="text-body-2">{{ tf.setup.secret }}</code>
              </div>
            </div>
            <p class="mb-2">2. Saisissez le code à 6 chiffres affiché par l'application :</p>
            <div class="d-flex ga-2" style="max-width: 360px">
              <v-text-field v-model="tf.code" label="Code" inputmode="numeric" autocomplete="one-time-code" hide-details />
              <v-btn color="primary" :loading="tf.loading" :disabled="!tf.code" @click="confirmEnable">Activer</v-btn>
            </div>
          </template>

          <template v-else-if="tf.status">
            <p class="mb-4">
              Statut :
              <v-chip :color="tf.status.enabled ? 'success' : 'grey'" size="small" variant="tonal">{{ tf.status.enabled ? 'Activée' : 'Désactivée' }}</v-chip>
              <span v-if="tf.status.enabled" class="text-body-2 text-medium-emphasis ml-2">{{ tf.status.recovery_codes_left }} code(s) de secours restant(s)</span>
            </p>
            <p class="text-body-2 mb-4">
              La double authentification demande, après le mot de passe, un code temporaire généré par votre téléphone :
              un mot de passe volé ne suffit plus pour accéder à votre boutique.
            </p>
            <v-text-field v-model="tf.password" label="Mot de passe actuel (confirmation)" type="password" autocomplete="current-password" style="max-width: 360px" />
            <div class="d-flex flex-wrap ga-2">
              <v-btn v-if="!tf.status.enabled" color="primary" :loading="tf.loading" :disabled="!tf.password" @click="startEnable">Activer la double authentification</v-btn>
              <template v-else>
                <v-btn variant="outlined" :loading="tf.loading" :disabled="!tf.password" @click="regenerate">Nouveaux codes de secours</v-btn>
                <v-btn color="error" variant="tonal" :loading="tf.loading" :disabled="!tf.password" @click="disable">Désactiver</v-btn>
              </template>
            </div>
          </template>
          <v-skeleton-loader v-else type="paragraph" />

          <v-alert v-if="tf.codes" type="warning" variant="tonal" class="mt-4" title="Codes de secours">
            Chaque code ne sert qu'une fois, si vous perdez l'accès à votre téléphone. Notez-les maintenant : ils ne seront plus affichés.
            <div class="d-flex flex-wrap ga-2 mt-2"><code v-for="c in tf.codes" :key="c" class="pa-1">{{ c }}</code></div>
          </v-alert>
        </v-card-text>
      </v-window-item>

      <v-window-item value="activity">
        <v-list v-if="activity.length" density="compact" lines="two">
          <v-list-item v-for="a in activity" :key="a.id" :prepend-icon="EVENT_ICONS[a.event] || 'mdi-information-outline'">
            <v-list-item-title>{{ a.description }}</v-list-item-title>
            <v-list-item-subtitle>
              {{ dateTime(a.created_at) }}<span v-if="a.ip"> · IP {{ a.ip }}</span>
              <span v-if="a.details?.sku"> · SKU {{ a.details.sku }}</span>
            </v-list-item-subtitle>
          </v-list-item>
        </v-list>
        <v-card-text v-else class="text-medium-emphasis">Aucune activité enregistrée.</v-card-text>
      </v-window-item>
    </v-window>
  </v-card>
</template>
