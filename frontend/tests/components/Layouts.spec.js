import { describe, expect, it, vi } from 'vitest'
import { byText, byUrl, click, flushPromises, mountView } from '../helpers'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
  ensureCsrf: vi.fn(),
}))
// Pas de WebSocket en test (le .env local peut configurer Reverb) : repli sur l'interrogation.
vi.mock('@/plugins/echo', () => ({ connectEcho: () => null, disconnectEcho: vi.fn() }))

const { api } = await import('@/services/api')
const { useAuthStore } = await import('@/stores/auth')
const { useInboxStore } = await import('@/stores/inbox')
const DashboardLayout = (await import('@/layouts/DashboardLayout.vue')).default
const PublicLayout = (await import('@/layouts/PublicLayout.vue')).default

const seller = { seller_id: 12, firstname: 'Amira', lastname: 'Trabelsi', email: 'amira@techshop.tn', shop_title: 'TechShop Sfax' }
const inboxItems = [
  { id: 3, type: 1, vendeur_viewed: 0, admin_viewed: 1, last_message: 'Nous avons relancé la commande.' },
  { id: 4, type: 1, vendeur_viewed: 0, admin_viewed: 0, last_message: 'Retour client' },
  { id: 5, type: 2, vendeur_viewed: 0, last_message: 'Résolue' },
]
const signedIn = () => {
  useAuthStore().seller = { ...seller }
}

describe('Gabarit de l\'espace vendeur', () => {
  it('affiche la boutique, le vendeur, le menu et le badge des réponses non lues', async () => {
    api.get.mockImplementation(byUrl({ '/api/reclamations/seller': inboxItems }))
    const { wrapper } = await mountView(DashboardLayout, { app: true, before: signedIn })

    expect(api.get).toHaveBeenCalledWith('/api/reclamations/seller')
    expect(wrapper.text()).toContain('TechShop Sfax')
    expect(wrapper.text()).toContain('Amira Trabelsi')
    for (const label of ['Tableau de bord', 'Mes produits', 'Import en masse', 'Relevés de paiement', 'Support', 'Mon compte']) {
      expect(wrapper.text()).toContain(label)
    }
    // Deux réclamations ouvertes avec réponse non lue (la résolue ne compte pas).
    const support = wrapper.findAll('.v-list-item').find((i) => i.text().includes('Support'))
    expect(support.find('.v-badge').text()).toBe('2')
    // Sans Reverb configuré : repli sur l'actualisation périodique.
    expect(wrapper.text()).toContain('Notifications : actualisation périodique')
    expect(useInboxStore().pollTimer).not.toBeNull()
  })

  it('déconnecte le vendeur et le renvoie vers la connexion', async () => {
    api.get.mockImplementation(byUrl({ '/api/reclamations/seller': inboxItems }))
    api.post.mockResolvedValue({ data: {} })
    const { wrapper, router } = await mountView(DashboardLayout, { app: true, before: signedIn })

    await wrapper.find('[aria-label="Compte de Amira Trabelsi"]').trigger('click')
    await flushPromises()
    await click(byText('Déconnexion', '.v-list-item'))

    expect(api.post).toHaveBeenCalledWith('/api/auth/logout')
    expect(useAuthStore().isAuthenticated).toBe(false)
    expect(useInboxStore().pollTimer).toBeNull()
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
  })

  it('arrête la réception des notifications quand on quitte l\'espace', async () => {
    api.get.mockImplementation(byUrl({ '/api/reclamations/seller': inboxItems }))
    const { wrapper } = await mountView(DashboardLayout, { app: true, before: signedIn })
    expect(useInboxStore().items).toHaveLength(3)

    wrapper.unmount()

    expect(useInboxStore().pollTimer).toBeNull()
    expect(useInboxStore().items).toHaveLength(0)
  })
})

describe('Gabarit public', () => {
  it('propose connexion et inscription à un visiteur', async () => {
    const { wrapper, router } = await mountView(PublicLayout, { app: true })

    expect(wrapper.text()).toContain('Espace réservé aux vendeurs partenaires Mytek')
    expect(wrapper.text()).toContain('Devenir vendeur')
    expect(wrapper.text()).toContain(`© ${new Date().getFullYear()} Mytek Informatique`)
    expect(wrapper.text()).not.toContain('Mon espace')

    await wrapper.findAll('a.v-btn').find((b) => b.text() === 'Se connecter').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('login')
  })

  it('propose l\'accès direct à son espace à un vendeur connecté', async () => {
    const { wrapper, router } = await mountView(PublicLayout, { app: true, before: signedIn })

    expect(wrapper.text()).not.toContain('Se connecter')
    await wrapper.findAll('a.v-btn').find((b) => b.text().includes('Mon espace')).trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('statistics')
  })
})
