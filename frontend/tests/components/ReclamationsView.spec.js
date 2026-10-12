import { beforeEach, describe, expect, it, vi } from 'vitest'
import { byText, click, flushPromises, mountView, type } from '../helpers'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}))
vi.mock('@/services/files', () => ({ downloadFile: vi.fn(async () => 'fichier'), openFile: vi.fn(async () => {}) }))

const { api } = await import('@/services/api')
const { downloadFile } = await import('@/services/files')
const { useInboxStore } = await import('@/stores/inbox')
const ReclamationsView = (await import('@/views/ReclamationsView.vue')).default

const inbox = () => [
  { id: 21, type: 1, vendeur_viewed: 0, admin_viewed: 1, last_message: 'Nous avons relancé le transporteur pour la commande 000184.', created_at: '2026-10-08T09:00:00Z', updated_at: '2026-10-10T16:00:00Z' },
  { id: 22, type: 1, vendeur_viewed: 1, admin_viewed: 0, last_message: '', created_at: '2026-10-11T09:00:00Z', updated_at: '2026-10-11T09:00:00Z' },
  { id: 15, type: 2, vendeur_viewed: 1, admin_viewed: 1, last_message: 'Paiement régularisé.', created_at: '2026-09-20T09:00:00Z', updated_at: '2026-09-22T09:00:00Z' },
  { id: 30, type: 0, vendeur_viewed: 0, admin_viewed: 1, last_message: 'Votre relevé de septembre est disponible.', created_at: '2026-10-01T08:00:00Z', updated_at: '2026-10-01T08:00:00Z' },
]
const messages = [
  { id: 1, sender: 1, message: 'Ma commande 000184 est bloquée depuis 5 jours.', created_at: '2026-10-08T09:00:00Z', attachments: [{ id: 9, filename: 'bon_livraison.pdf', file_type: 'application/pdf' }] },
  { id: 2, sender: 0, message: 'Nous avons relancé le transporteur pour la commande 000184.', created_at: '2026-10-10T16:00:00Z', attachments: [] },
]

function apiOk(items = inbox()) {
  api.get.mockImplementation((url) => Promise.resolve({ data: url === '/api/reclamations/seller' ? items : messages }))
  api.put.mockResolvedValue({ data: {} })
}

describe('Centre de support', () => {
  beforeEach(() => {
    api.get.mockReset()
    api.post.mockReset()
  })

  it('liste les réclamations ouvertes avec les compteurs', async () => {
    apiOk()
    const { wrapper } = await mountView(ReclamationsView)

    expect(wrapper.text()).toContain('2 ouverte(s) · 1 résolue(s) · 1 en attente de lecture par le support')
    expect(wrapper.text()).toContain('#21')
    expect(wrapper.text()).toContain('#22')
    expect(wrapper.text()).not.toContain('#15')
    expect(wrapper.text()).toContain('Aucun message, ouvrez la conversation')

    await click(byText('Résolues', '.v-chip'))
    expect(wrapper.text()).toContain('#15')
    expect(wrapper.text()).not.toContain('#21')
  })

  it('ouvre une conversation, la marque comme lue et répond', async () => {
    apiOk()
    api.post.mockResolvedValue({ data: {} })
    const { wrapper } = await mountView(ReclamationsView)

    await click(byText('#21', '.v-list-item'))
    expect(api.get).toHaveBeenCalledWith('/api/reclamations/21/messages')
    expect(api.put).toHaveBeenCalledWith('/api/reclamations/21/seen-by-seller')
    expect(wrapper.text()).toContain('Réclamation #21')
    expect(wrapper.text()).toContain('Ma commande 000184 est bloquée depuis 5 jours.')
    expect(wrapper.text()).toContain('Support Mytek')
    expect(wrapper.find('.mdi-file-pdf-box').exists()).toBe(true)

    await wrapper.find('[aria-label="Télécharger bon_livraison.pdf"]').trigger('click')
    expect(downloadFile).toHaveBeenCalledWith('/api/attachments/download/bon_livraison.pdf', {}, 'bon_livraison.pdf')

    await wrapper.find('textarea').setValue('Merci, je surveille la livraison.')
    await wrapper.findAll('button').find((b) => b.text() === 'Envoyer').trigger('click')
    await flushPromises()

    const [url, body] = api.post.mock.calls[0]
    expect(url).toBe('/api/reclamations/21/reply')
    expect(body.get('message')).toBe('Merci, je surveille la livraison.')
    expect(wrapper.find('textarea').element.value).toBe('')
  })

  it('clôt une réclamation résolue', async () => {
    apiOk()
    const { wrapper } = await mountView(ReclamationsView)
    await click(byText('#21', '.v-list-item'))

    await wrapper.findAll('button').find((b) => b.text().includes('Problème résolu')).trigger('click')
    await flushPromises()
    await click(byText("Oui, c'est résolu"))

    expect(api.put).toHaveBeenCalledWith('/api/reclamations/21/resolve')
    expect(document.body.textContent).toContain('Réclamation marquée comme résolue')
  })

  it('exige un message pour créer une réclamation puis l\'envoie', async () => {
    apiOk()
    api.post.mockResolvedValue({ data: { reclamation_id: 23 } })
    const { wrapper } = await mountView(ReclamationsView)

    await wrapper.find('[data-test=new]').trigger('click')
    await flushPromises()
    await click(document.querySelector('[data-test=create-send]'))
    expect(document.querySelector('[data-test=create-error]').textContent).toContain('Veuillez saisir un message.')
    expect(api.post).not.toHaveBeenCalled()

    await type(document.querySelector('[data-test=create-text] textarea'), 'Un client signale un produit reçu endommagé.')
    await click(document.querySelector('[data-test=create-send]'))

    const [url, body] = api.post.mock.calls[0]
    expect(url).toBe('/api/reclamations')
    expect(body.get('message')).toBe('Un client signale un produit reçu endommagé.')
    expect(api.get).toHaveBeenCalledWith('/api/reclamations/23/messages')
    expect(document.body.textContent).toContain('Réclamation envoyée avec succès')
  })

  it('ouvre directement la notification demandée dans l\'adresse', async () => {
    apiOk()
    const { wrapper } = await mountView(ReclamationsView, { path: '/reclamations?notification=30' })

    expect(api.get).toHaveBeenCalledWith('/api/reclamations/30/messages')
    expect(wrapper.text()).toContain('Notification #30')
    expect(useInboxStore().unreadNotifications).toHaveLength(0)
    // Une notification n'accepte pas de réponse.
    expect(wrapper.find('textarea').exists()).toBe(false)
  })

  it('recharge la conversation ouverte à la réception d\'un message en temps réel', async () => {
    apiOk()
    await mountView(ReclamationsView)
    await click(byText('#22', '.v-list-item'))
    api.get.mockClear()

    useInboxStore().lastEvent = { kind: 'reply', reclamation_id: 22 }
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/api/reclamations/22/messages')
  })
})
