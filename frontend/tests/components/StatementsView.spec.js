import { describe, expect, it, vi } from 'vitest'
import { mountView } from '../helpers'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { get: vi.fn() },
}))
vi.mock('@/services/files', () => ({ downloadFile: vi.fn() }))

const { api } = await import('@/services/api')
const { downloadFile } = await import('@/services/files')
const StatementsView = (await import('@/views/StatementsView.vue')).default

const statements = [
  { period: '2026-09', orders_count: 42, gross: '6120.000', commission: '734.400', net: '5385.600', status: 'pending', paid_at: null },
  { period: '2026-08', orders_count: 35, gross: '4980.500', commission: '597.660', net: '4382.840', status: 'paid', paid_at: '2026-09-05T09:00:00Z' },
]

describe('Relevés de paiement', () => {
  it('liste les relevés mensuels avec montants, taux et statut', async () => {
    api.get.mockResolvedValue({ data: { statements, commission_rate: 0.12 } })
    const { wrapper } = await mountView(StatementsView)

    expect(api.get).toHaveBeenCalledWith('/api/statements')
    expect(wrapper.text()).toContain('commission Mytek (12 %)')
    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Septembre 2026')
    expect(rows[0].text()).toContain('En attente')
    expect(rows[0].text()).toMatch(/5\s?385,60 DT/)
    expect(rows[1].text()).toContain('Août 2026')
    expect(rows[1].text()).toContain('Payé le 05/09/2026')
  })

  it('télécharge le PDF du mois choisi', async () => {
    api.get.mockResolvedValue({ data: { statements, commission_rate: 0.12 } })
    downloadFile.mockResolvedValue('releve_2026-08.pdf')
    const { wrapper } = await mountView(StatementsView)

    await wrapper.findAll('tbody tr')[1].find('button').trigger('click')

    expect(downloadFile).toHaveBeenCalledWith('/api/statements/2026-08/pdf', {}, 'releve_2026-08.pdf')
  })

  it('signale un téléchargement impossible', async () => {
    api.get.mockResolvedValue({ data: { statements, commission_rate: 0.12 } })
    downloadFile.mockRejectedValue({ response: { status: 404, data: new Blob() } })
    const { wrapper } = await mountView(StatementsView)

    await wrapper.find('tbody tr button').trigger('click')
    await new Promise((r) => setTimeout(r))

    expect(wrapper.find('.v-alert').text()).toBe('Téléchargement impossible.')
  })

  it('explique l\'absence de relevé et affiche les erreurs de chargement', async () => {
    api.get.mockResolvedValueOnce({ data: { statements: [], commission_rate: 0.1 } })
    const empty = await mountView(StatementsView)
    expect(empty.wrapper.text()).toContain('Aucun relevé pour le moment')

    api.get.mockRejectedValueOnce(new Error('réseau'))
    const failed = await mountView(StatementsView)
    expect(failed.wrapper.text()).toContain('Impossible de joindre le serveur.')
  })
})
