<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'
import BrandLogo from '@/components/BrandLogo.vue'
import NotificationBell from '@/components/NotificationBell.vue'
import { useAuthStore } from '@/stores/auth'
import { useInboxStore } from '@/stores/inbox'

const auth = useAuthStore()
const inbox = useInboxStore()
const router = useRouter()
const { mdAndUp } = useDisplay()
const drawer = ref(mdAndUp.value)

const menu = [
  { title: 'Tableau de bord', icon: 'mdi-chart-box-outline', to: { name: 'statistics' } },
  { title: 'Mes produits', icon: 'mdi-package-variant-closed', to: { name: 'products' } },
  { title: 'Ajouter un produit', icon: 'mdi-plus-box-outline', to: { name: 'add-product' } },
  { title: 'Import en masse', icon: 'mdi-file-upload-outline', to: { name: 'import' } },
  { title: 'Mes ventes', icon: 'mdi-cash-register', to: { name: 'sales' } },
  { title: 'Relevés de paiement', icon: 'mdi-file-document-outline', to: { name: 'statements' } },
  { title: 'Support', icon: 'mdi-lifebuoy', to: { name: 'reclamations' }, badge: true },
  { title: 'Mon compte', icon: 'mdi-account-circle-outline', to: { name: 'account' } },
]

onMounted(() => inbox.start(auth.seller.seller_id))
onBeforeUnmount(() => inbox.stop())

async function logout() {
  inbox.stop()
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <v-navigation-drawer v-model="drawer" :permanent="mdAndUp" width="260">
    <div class="pa-4"><BrandLogo /></div>
    <v-list density="comfortable" nav color="primary">
      <v-list-subheader>Menu vendeur</v-list-subheader>
      <v-list-item v-for="item in menu" :key="item.title" :to="item.to" :prepend-icon="item.icon" :title="item.title">
        <template v-if="item.badge && inbox.unreadReclamations > 0" #append>
          <v-badge :content="inbox.unreadReclamations" color="primary" inline />
        </template>
      </v-list-item>
    </v-list>
    <template #append>
      <div class="pa-4 text-caption text-medium-emphasis d-flex align-center">
        <v-icon :color="inbox.realtime ? 'success' : 'grey'" size="10" class="mr-2">mdi-circle</v-icon>
        {{ inbox.realtime ? 'Notifications en temps réel' : 'Notifications : actualisation périodique' }}
      </div>
    </template>
  </v-navigation-drawer>

  <v-app-bar flat border="b">
    <v-app-bar-nav-icon v-if="!mdAndUp" aria-label="Ouvrir le menu" @click="drawer = !drawer" />
    <v-app-bar-title class="text-body-1">
      <span class="text-medium-emphasis">Boutique</span> <strong>{{ auth.seller?.shop_title }}</strong>
    </v-app-bar-title>
    <NotificationBell />
    <v-menu>
      <template #activator="{ props }">
        <v-btn v-bind="props" variant="text" class="ml-1" prepend-icon="mdi-account-circle" :aria-label="`Compte de ${auth.displayName}`">
          <span class="d-none d-sm-inline">{{ auth.displayName }}</span>
        </v-btn>
      </template>
      <v-list density="compact">
        <v-list-item :to="{ name: 'account' }" prepend-icon="mdi-account-cog-outline" title="Mon compte" />
        <v-list-item prepend-icon="mdi-logout" title="Déconnexion" @click="logout" />
      </v-list>
    </v-menu>
  </v-app-bar>

  <v-main>
    <v-container fluid class="pa-4 pa-md-6">
      <router-view />
    </v-container>
  </v-main>
</template>
