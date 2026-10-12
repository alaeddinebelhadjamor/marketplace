import { describe, expect, it, vi } from 'vitest'
import { byText, click, flushPromises, mountView } from '../helpers'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { get: vi.fn(), put: vi.fn() },
}))

const { api } = await import('@/services/api')
const { useInboxStore } = await import('@/stores/inbox')
const NotificationBell = (await import('@/components/NotificationBell.vue')).default

const items = [
  { id: 41, type: 0, vendeur_viewed: 0, last_message: 'Votre produit SKU-CASQUE-01 a été validé', created_at: '2026-10-10T09:00:00Z' },
  { id: 42, type: 0, vendeur_viewed: 0, last_message: 'Relevé de septembre disponible', created_at: '2026-10-11T08:00:00Z' },
  { id: 40, type: 0, vendeur_viewed: 1, last_message: 'Bienvenue sur Mytek Marketplace', created_at: '2026-10-01T08:00:00Z' },
  { id: 7, type: 1, vendeur_viewed: 0, last_message: 'Commande bloquée', created_at: '2026-10-09T08:00:00Z' },
]

const fill = (list) => () => {
  useInboxStore().items = structuredClone(list)
}

describe('Cloche de notifications', () => {
  it('affiche le nombre de notifications non lues (hors réclamations)', async () => {
    const { wrapper } = await mountView(NotificationBell, { before: fill(items) })
    expect(wrapper.find('button').attributes('aria-label')).toBe('Notifications, 2 non lue(s)')
    expect(wrapper.find('.v-badge__badge').text()).toBe('2')
  })

  it("marque tout comme lu à l'ouverture et liste les plus récentes d'abord", async () => {
    api.put.mockResolvedValue({ data: {} })
    const { wrapper } = await mountView(NotificationBell, { before: fill(items) })

    await wrapper.find('button').trigger('click')
    await flushPromises()

    const titles = [...document.querySelectorAll('.v-list-item-title')].map((el) => el.textContent)
    expect(titles).toEqual(['Relevé de septembre disponible', 'Votre produit SKU-CASQUE-01 a été validé', 'Bienvenue sur Mytek Marketplace'])
    expect(api.put).toHaveBeenCalledWith('/api/reclamations/41/seen-by-seller')
    expect(api.put).toHaveBeenCalledWith('/api/reclamations/42/seen-by-seller')
    expect(api.put).toHaveBeenCalledTimes(2)
    expect(useInboxStore().unreadNotifications).toHaveLength(0)
  })

  it('ouvre la notification choisie dans le centre de support', async () => {
    api.put.mockResolvedValue({ data: {} })
    const { wrapper, router } = await mountView(NotificationBell, { before: fill(items) })
    await wrapper.find('button').trigger('click')
    await flushPromises()

    await click(byText('Relevé de septembre', '.v-list-item'))

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('reclamations'))
    expect(router.currentRoute.value.query).toEqual({ notification: '42' })
  })

  it('indique une boîte vide et affiche un message à chaque événement temps réel', async () => {
    const { wrapper } = await mountView(NotificationBell)
    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('Aucune notification')

    useInboxStore().lastEvent = { kind: 'reply', reclamation_id: 7 }
    await flushPromises()
    expect(document.body.textContent).toContain('Nouvelle réponse du support')

    useInboxStore().lastEvent = { kind: 'notification', preview: 'Nouveau relevé disponible' }
    await flushPromises()
    expect(document.body.textContent).toContain('Nouveau relevé disponible')
  })
})
