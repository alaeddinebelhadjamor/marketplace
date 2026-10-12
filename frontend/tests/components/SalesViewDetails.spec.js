import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mountView } from '../helpers'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { get: vi.fn() },
}))
vi.mock('@/services/files', () => ({ downloadFile: vi.fn() }))

const { api } = await import('@/services/api')
const { downloadFile } = await import('@/services/files')
const SalesView = (await import('@/views/SalesView.vue')).default

const orders = [
  { order_id: '000184', sku: 'CASQUE-JBL-T510', product_name: 'Casque JBL Tune 510BT', qty: 2, price: '119.00', effective_at: '2026-10-09T10:00:00Z' },
  { order_id: '000191', sku: 'CASQUE-JBL-T510', product_name: 'Casque JBL Tune 510BT', qty: 1, price: '129.00', effective_at: '2026-10-11T10:00:00Z' },
]
const exportButton = (wrapper) => wrapper.findAll('button').find((b) => /export/i.test(b.text()))

describe('Historique des ventes : détail et export', () => {
  it('ouvre le détail des commandes d\'une référence', async () => {
    api.get.mockResolvedValue({ data: orders })
    const { wrapper } = await mountView(SalesView)

    await wrapper.find('tbody tr').trigger('click')
    await flushPromises()

    const dialog = document.querySelector('.v-dialog')
    expect(dialog.textContent).toContain('Détail des commandes — CASQUE-JBL-T510')
    expect(dialog.querySelectorAll('tbody tr')).toHaveLength(2)
    expect(dialog.textContent).toContain('000191')
    expect(dialog.textContent).toContain('11/10/2026')
  })

  it('exporte les ventes filtrées en Excel', async () => {
    api.get.mockResolvedValue({ data: orders })
    downloadFile.mockResolvedValue('commandes.xlsx')
    const { wrapper } = await mountView(SalesView)
    await wrapper.find('[data-test=sku] input').setValue('casque')

    await exportButton(wrapper).trigger('click')

    expect(downloadFile).toHaveBeenCalledWith('/api/orders/export', { from: undefined, to: undefined, sku: 'casque' }, 'commandes.xlsx')
  })

  it('signale un export impossible et une erreur de chargement', async () => {
    api.get.mockResolvedValueOnce({ data: orders })
    downloadFile.mockRejectedValue({ response: { status: 500, data: new Blob() } })
    const { wrapper } = await mountView(SalesView)
    await exportButton(wrapper).trigger('click')
    await flushPromises()
    expect(wrapper.find('.v-alert').text()).toBe('Export impossible.')

    api.get.mockRejectedValueOnce({ response: { status: 503, data: { message: 'Magento ne répond pas.' } } })
    const failed = await mountView(SalesView)
    expect(failed.wrapper.text()).toContain('Magento ne répond pas.')
    expect(failed.wrapper.text()).toContain('Aucune vente trouvée.')
  })
})
