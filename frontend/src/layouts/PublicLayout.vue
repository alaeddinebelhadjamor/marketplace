<script setup>
import BrandLogo from '@/components/BrandLogo.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <v-system-bar color="secondary" class="justify-center text-caption">
    Espace réservé aux vendeurs partenaires Mytek · Besoin d'aide ? vendeurs@mytek.tn · 36 360 000
  </v-system-bar>

  <v-app-bar flat border="b" color="surface">
    <v-container class="d-flex align-center py-0">
      <BrandLogo />
      <v-spacer />
      <template v-if="auth.isAuthenticated">
        <v-btn color="primary" variant="flat" :to="{ name: 'statistics' }" prepend-icon="mdi-view-dashboard">Mon espace</v-btn>
      </template>
      <template v-else>
        <v-btn variant="text" :to="{ name: 'login' }" class="mr-2">Se connecter</v-btn>
        <v-btn color="primary" variant="flat" :to="{ name: 'register' }" class="d-none d-sm-flex">Devenir vendeur</v-btn>
      </template>
    </v-container>
  </v-app-bar>

  <v-main>
    <router-view />
  </v-main>

  <v-footer color="secondary" class="pa-6">
    <v-container>
      <v-row>
        <v-col cols="12" md="5">
          <div class="font-weight-bold mb-1">Mytek Marketplace</div>
          <div class="text-body-2 text-medium-emphasis">La plateforme qui connecte les vendeurs professionnels au n°1 du high-tech en Tunisie.</div>
        </v-col>
        <v-col cols="6" md="3">
          <div class="font-weight-bold mb-1">Liens rapides</div>
          <div><router-link class="text-white" to="/register">Devenir vendeur</router-link></div>
          <div><router-link class="text-white" to="/login">Espace vendeur</router-link></div>
        </v-col>
        <v-col cols="6" md="4">
          <div class="font-weight-bold mb-1">Contact</div>
          <div class="text-body-2">vendeurs@mytek.tn</div>
          <div class="text-body-2">36 360 000</div>
        </v-col>
      </v-row>
      <v-divider class="my-4" />
      <div class="text-caption text-center">© {{ new Date().getFullYear() }} Mytek Informatique. Tous droits réservés.</div>
    </v-container>
  </v-footer>
</template>
