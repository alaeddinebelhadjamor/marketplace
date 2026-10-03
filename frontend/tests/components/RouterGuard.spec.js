import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { get: vi.fn() },
}))

const { api } = await import('@/services/api')

describe('Garde de navigation', () => {
  beforeEach(() => {
    vi.resetModules()
    setActivePinia(createPinia())
  })

  it('renvoie vers la connexion sans session, en gardant la page demandée', async () => {
    api.get.mockRejectedValue({ response: { status: 403 } })
    const router = (await import('@/router')).default
    await router.push('/dashboard/sales')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/dashboard/sales')
  })

  it('laisse passer un vendeur connecté et le détourne des pages invités', async () => {
    api.get.mockResolvedValue({ data: { seller_id: 6 } })
    const router = (await import('@/router')).default
    await router.push('/dashboard/sales')
    expect(router.currentRoute.value.name).toBe('sales')

    await router.push('/login')
    expect(router.currentRoute.value.name).toBe('statistics')
  })
})
