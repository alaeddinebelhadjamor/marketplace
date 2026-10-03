import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mountView } from '../helpers'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { get: vi.fn() },
}))

const { api } = await import('@/services/api')
const SalesView = (await import('@/views/SalesView.vue')).default

const orders = [
  { order_id: '1', sku: 'CLAVIER-1', product_name: 'Clavier', qty: 2, price: '50.00', effective_at: '2026-09-10T10:00:00Z' },
  { order_id: '2', sku: 'CLAVIER-1', product_name: 'Clavier', qty: 1, price: '50.00', effective_at: '2026-09-11T10:00:00Z' },
  { order_id: '2', sku: 'SOURIS-1', product_name: 'Souris', qty: 1, price: '20.00', effective_at: '2026-09-11T10:00:00Z' },
]

describe('Historique des ventes', () => {
  it('regroupe par référence et totalise les commandes distinctes', async () => {
    api.get.mockResolvedValue({ data: orders })
    const { wrapper } = await mountView(SalesView)

    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('CLAVIER-1')
    expect(wrapper.text()).toContain('2 commande(s), 4 article(s)')
  })

  it('filtre par SKU sans rappeler l\'API', async () => {
    api.get.mockResolvedValue({ data: orders })
    const { wrapper } = await mountView(SalesView)
    api.get.mockClear()

    await wrapper.find('[data-test=sku] input').setValue('souris')
    await flushPromises()

    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(api.get).not.toHaveBeenCalled()
  })

  it('invite à corriger une plage de dates inversée sans interroger l\'API', async () => {
    api.get.mockResolvedValue({ data: orders })
    const { wrapper } = await mountView(SalesView)
    // Une date de début seule est une plage valide : elle déclenche une requête.
    await wrapper.find('[data-test=from] input').setValue('2026-09-15')
    await flushPromises()
    api.get.mockClear()

    await wrapper.find('[data-test=to] input').setValue('2026-09-01')
    await flushPromises()

    expect(wrapper.find('[data-test=range-error]').text()).toMatch(/corrigez la plage de dates/)
    expect(api.get).not.toHaveBeenCalled()
  })
})
