<script setup>
defineProps({ dark: { type: Boolean, default: false } })
</script>

<template>
  <router-link to="/" class="brand d-flex align-center text-decoration-none" aria-label="Mytek Marketplace, accueil">
    <svg width="32" height="32" viewBox="0 0 64 64" aria-hidden="true">
      <rect width="64" height="64" rx="14" fill="#c70a0a" />
      <path d="M16 46V18h7l9 14 9-14h7v28h-7V30l-9 13-9-13v16z" fill="#fff" />
    </svg>
    <span class="ml-2 font-weight-bold" :class="dark ? 'text-white' : 'text-secondary'">Mytek</span>
    <v-chip size="x-small" color="primary" variant="flat" class="ml-2">Marketplace</v-chip>
  </router-link>
</template>
