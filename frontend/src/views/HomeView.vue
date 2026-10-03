<script setup>
const features = [
  { icon: 'mdi-eye-outline', title: 'Visibilité maximale', text: 'Profitez du trafic web n°1 en Tunisie dans le secteur informatique et électroménager.' },
  { icon: 'mdi-chart-bar', title: 'Gestion simplifiée', text: 'Un tableau de bord pour gérer vos produits, suivre vos ventes et vos commissions en temps réel.' },
  { icon: 'mdi-shield-check-outline', title: 'Paiement sécurisé', text: 'Relevés de paiement mensuels détaillés et compte protégé par double authentification.' },
]
const steps = [
  { title: 'Créez votre compte', text: 'Remplissez le formulaire avec les informations de votre boutique.' },
  { title: 'Validation par nos équipes', text: 'Notre équipe vérifie votre dossier et active votre espace sous 48 h.' },
  { title: 'Vendez sur Mytek', text: 'Ajoutez vos produits, un par un ou par fichier Excel, et suivez vos ventes.' },
]
</script>

<template>
  <section class="hero py-16">
    <v-container>
      <v-row align="center">
        <v-col cols="12" md="7">
          <div class="text-overline text-primary">Mytek Marketplace</div>
          <h1 class="text-h3 font-weight-bold mb-4">Vendez vos produits high-tech sur <span class="text-primary">Mytek</span></h1>
          <p class="text-body-1 text-medium-emphasis mb-6">
            Rejoignez la marketplace n°1 de l'informatique et de l'électroménager en Tunisie et touchez des milliers de clients chaque jour.
          </p>
          <div class="d-flex flex-wrap ga-3">
            <v-btn size="large" color="primary" :to="{ name: 'register' }">Commencer à vendre</v-btn>
            <v-btn size="large" variant="outlined" :to="{ name: 'login' }">J'ai déjà un compte</v-btn>
          </div>
        </v-col>
        <v-col cols="12" md="5" class="d-none d-md-flex justify-center">
          <v-icon size="220" color="primary" class="opacity-80">mdi-storefront-outline</v-icon>
        </v-col>
      </v-row>
    </v-container>
  </section>

  <v-container class="py-12">
    <h2 class="text-h5 font-weight-bold text-center mb-8">Pourquoi vendre sur Mytek ?</h2>
    <v-row>
      <v-col v-for="f in features" :key="f.title" cols="12" md="4">
        <v-card variant="outlined" class="h-100 pa-4">
          <v-icon color="primary" size="36">{{ f.icon }}</v-icon>
          <v-card-title class="px-0">{{ f.title }}</v-card-title>
          <v-card-text class="px-0">{{ f.text }}</v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <h2 class="text-h5 font-weight-bold text-center mt-12 mb-8">Comment devenir vendeur ?</h2>
    <v-row>
      <v-col v-for="(s, i) in steps" :key="s.title" cols="12" md="4">
        <v-card class="h-100 pa-4" elevation="1">
          <v-avatar color="primary" class="mb-3">{{ i + 1 }}</v-avatar>
          <div class="text-subtitle-1 font-weight-bold">{{ s.title }}</div>
          <div class="text-body-2 text-medium-emphasis">{{ s.text }}</div>
        </v-card>
      </v-col>
    </v-row>
    <div class="text-center mt-8">
      <v-btn color="primary" size="large" :to="{ name: 'register' }">Devenir vendeur partenaire</v-btn>
    </div>
  </v-container>
</template>

<style scoped>
.hero { background: linear-gradient(135deg, #fff 0%, #fdecec 100%); }
</style>
