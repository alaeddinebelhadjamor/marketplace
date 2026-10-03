import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/services/api', () => ({
  api: { get: vi.fn(), put: vi.fn() },
}))
vi.mock('@/plugins/echo', () => ({ connectEcho: () => null, disconnectEcho: () => {} }))

const { api } = await import('@/services/api')
const { useInboxStore } = await import('@/stores/inbox')

describe('Boîte du vendeur (notifications et réclamations)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
    api.get.mockResolvedValue({
      data: [
        { id: 1, type: 0, vendeur_viewed: 0 },
        { id: 2, type: 0, vendeur_viewed: 1 },
        { id: 3, type: 1, vendeur_viewed: 0 },
        { id: 4, type: 2, vendeur_viewed: 0 },
      ],
    })
    api.put.mockResolvedValue({ data: { success: true } })
  })

  it('compte les notifications non lues et les réclamations ouvertes non lues', async () => {
    const inbox = useInboxStore()
    await inbox.refresh()

    expect(inbox.unreadNotifications.map((n) => n.id)).toEqual([1])
    expect(inbox.unreadReclamations).toBe(1)
    expect(inbox.reclamations).toHaveLength(2)
  })

  it('marque toutes les notifications comme lues à l\'ouverture de la cloche', async () => {
    const inbox = useInboxStore()
    await inbox.refresh()
    await inbox.markAllNotificationsSeen()

    expect(api.put).toHaveBeenCalledWith('/api/reclamations/1/seen-by-seller')
    expect(inbox.unreadNotifications).toHaveLength(0)
  })

  it('se replie sur une actualisation toutes les 60 s sans temps réel', async () => {
    const inbox = useInboxStore()
    await inbox.start(6)
    api.get.mockClear()

    vi.advanceTimersByTime(60000)
    expect(api.get).toHaveBeenCalledWith('/api/reclamations/seller')
    inbox.stop()
  })
})
