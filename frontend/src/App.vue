<script setup>
import { useRouter } from 'vue-router'
import { setUnauthenticatedHandler } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useInboxStore } from '@/stores/inbox'

const router = useRouter()
const auth = useAuthStore()
const inbox = useInboxStore()

// Session expirée ou compte désactivé : retour à la connexion.
setUnauthenticatedHandler(() => {
  if (!auth.isAuthenticated) return
  auth.clear()
  inbox.stop()
  router.push({ name: 'login', query: { expired: '1' } })
})
</script>

<template>
  <v-app>
    <router-view />
  </v-app>
</template>
