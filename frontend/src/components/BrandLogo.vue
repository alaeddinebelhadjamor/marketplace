<script setup>
import logoMytek from '@/assets/logo_mytek.png'

defineProps({ dark: { type: Boolean, default: false } })
</script>

<template>
  <router-link to="/" class="brand d-flex align-center text-decoration-none" aria-label="Mytek Marketplace, accueil">
    <img :src="logoMytek" alt="Mytek" height="32" class="brand-logo" />
    <v-chip size="x-small" color="primary" variant="flat" class="ml-2">Marketplace</v-chip>
  </router-link>
</template>

<style scoped>
.brand-logo {
  height: 32px;
  width: auto;
  display: block;
}
</style>
