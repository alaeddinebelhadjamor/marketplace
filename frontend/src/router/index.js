import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import PublicLayout from '@/layouts/PublicLayout.vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'

export const routes = [
  {
    path: '/',
    component: PublicLayout,
    children: [
      { path: '', name: 'home', component: () => import('@/views/HomeView.vue'), meta: { title: 'Accueil' } },
      { path: 'login', name: 'login', component: () => import('@/views/auth/LoginView.vue'), meta: { guest: true, title: 'Connexion' } },
      { path: 'register', name: 'register', component: () => import('@/views/auth/RegisterView.vue'), meta: { guest: true, title: 'Devenir vendeur' } },
      { path: 'forgot-password', name: 'forgot-password', component: () => import('@/views/auth/ForgotPasswordView.vue'), meta: { guest: true, title: 'Mot de passe oublié' } },
      { path: 'reset-password', name: 'reset-password', component: () => import('@/views/auth/ResetPasswordView.vue'), meta: { guest: true, title: 'Nouveau mot de passe' } },
    ],
  },
  { path: '/home', redirect: '/' },
  {
    path: '/dashboard',
    component: DashboardLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', redirect: { name: 'statistics' } },
      { path: 'statistics', name: 'statistics', component: () => import('@/views/StatisticsView.vue'), meta: { title: 'Tableau de bord' } },
      { path: 'products', name: 'products', component: () => import('@/views/ProductsView.vue'), meta: { title: 'Mes produits' } },
      { path: 'add-product', name: 'add-product', component: () => import('@/views/AddProductView.vue'), meta: { title: 'Ajouter un produit' } },
      { path: 'import', name: 'import', component: () => import('@/views/ImportProductsView.vue'), meta: { title: 'Import en masse' } },
      { path: 'sales', name: 'sales', component: () => import('@/views/SalesView.vue'), meta: { title: 'Mes ventes' } },
      { path: 'statements', name: 'statements', component: () => import('@/views/StatementsView.vue'), meta: { title: 'Relevés de paiement' } },
      { path: 'reclamations', name: 'reclamations', component: () => import('@/views/ReclamationsView.vue'), meta: { title: 'Support' } },
      { path: 'account', name: 'account', component: () => import('@/views/AccountView.vue'), meta: { title: 'Mon compte' } },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue'), meta: { title: 'Page introuvable' } },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.restore()

  if (to.matched.some((r) => r.meta.requiresAuth) && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.meta.guest && auth.isAuthenticated) {
    return { name: 'statistics' }
  }
  return true
})

router.afterEach((to) => {
  document.title = `${to.meta.title ? to.meta.title + ' — ' : ''}Mytek Marketplace`
})

export default router
