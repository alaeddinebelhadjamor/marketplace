import { beforeEach, describe, expect, it, vi } from 'vitest'
import { byText, click, flushPromises, mountView } from '../helpers'
import { isoDay } from '@/utils/format'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { get: vi.fn() },
}))

const { api } = await import('@/services/api')
const StatisticsView = (await import('@/views/StatisticsView.vue')).default

const today = isoDay()
const yesterday = isoDay(Date.now() - 86400000)
const dashboard = {
  commission_rate: 0.12,
  kpis: {
    revenue: { value: 4250.5, previous: 3400, change_pct: 25 },
    orders: { value: 31, previous: 34, change_pct: -8.8 },
    items: { value: 47, previous: 47, change_pct: 0 },
    average_basket: { value: 137.11, previous: 100, change_pct: 37.1 },
    net_revenue: { value: 3740.44 },
  },
  series: {
    current: [{ date: yesterday, revenue: 1800 }, { date: today, revenue: 2450.5 }],
    previous: [{ date: '2026-08-01', revenue: 1500 }, { date: '2026-08-02', revenue: 1900 }],
  },
  by_weekday: [{ day: 'Lundi', revenue: 900 }, { day: 'Mardi', revenue: 1200 }],
  top_products: [
    { sku: 'CASQUE-JBL-T510', name: 'Casque JBL Tune 510BT', qty: 12, revenue: 1428 },
    { sku: 'SOURIS-LOGI-M185', name: 'Souris Logitech M185', qty: 9, revenue: 359.1 },
  ],
}
const orders = [
  { order_id: '100', sku: 'CASQUE-JBL-T510', product_name: 'Casque JBL Tune 510BT', qty: 2, price: '119.00', effective_at: `${today}T10:00:00` },
  { order_id: '101', sku: 'casque-jbl-t510', product_name: 'Casque JBL Tune 510BT', qty: 1, price: '119.00', effective_at: `${yesterday}T15:00:00` },
]

function apiOk() {
  api.get.mockImplementation((url) => Promise.resolve({ data: url === '/api/dashboard' ? structuredClone(dashboard) : orders }))
}

// Les graphiques ECharts (canvas) ne fonctionnent pas sous jsdom : on vérifie les options transmises.
const stubs = { EChart: { props: ['option', 'label', 'height'], template: '<div class="echart-stub" :aria-label="label" />' } }

describe('Tableau de bord', () => {
  beforeEach(() => {
    localStorage.clear()
    api.get.mockReset()
  })

  it('charge les indicateurs des 30 derniers jours et les affiche', async () => {
    apiOk()
    const { wrapper } = await mountView(StatisticsView, { stubs })

    const params = { from: isoDay(Date.now() - 29 * 86400000), to: today }
    expect(api.get).toHaveBeenCalledWith('/api/dashboard', { params })
    expect(api.get).toHaveBeenCalledWith('/api/orders', { params })

    const text = wrapper.text()
    expect(text).toContain("Chiffre d'affaires")
    expect(text).toMatch(/4\s?250,50 DT/)
    expect(text).toContain('+25 %')
    expect(text).toContain('-8,8 %')
    expect(text).toContain('Net estimé après commission (12 %)')
    expect(text).toContain('Casque JBL Tune 510BT')
    expect(text).toContain('SOURIS-LOGI-M185')

    const revenue = wrapper.findAllComponents(stubs.EChart)[0].props('option')
    expect(revenue.series[0].data).toEqual([1800, 2450.5])
    expect(revenue.series[1].data).toEqual([1500, 1900])
    expect(revenue.tooltip.valueFormatter(10)).toMatch(/^10,00 DT$/)
    expect(revenue.yAxis.axisLabel.formatter(1500)).toMatch(/1\s?500 DT/)
    const weekday = wrapper.findAllComponents(stubs.EChart)[1].props('option')
    expect(weekday.xAxis.data).toEqual(['Lun', 'Mar'])
  })

  it('recharge les données quand on choisit une autre période', async () => {
    apiOk()
    await mountView(StatisticsView, { stubs })
    api.get.mockClear()

    await click(byText('7 jours', '.v-chip'))

    expect(api.get).toHaveBeenCalledWith('/api/dashboard', { params: { from: isoDay(Date.now() - 6 * 86400000), to: today } })
  })

  it('signale une plage personnalisée inversée sans interroger l\'API', async () => {
    apiOk()
    const { wrapper } = await mountView(StatisticsView, { stubs })
    await click(byText('Personnalisée', '.v-chip'))
    api.get.mockClear()

    const [from, to] = wrapper.findAll('input[type=date]')
    await from.setValue('2026-09-30')
    await to.setValue('2026-09-01')
    await flushPromises()

    expect(wrapper.find('.v-alert').text()).toMatch(/date/i)
    expect(api.get).not.toHaveBeenCalledWith('/api/dashboard', { params: { from: '2026-09-30', to: '2026-09-01' } })
  })

  it('compare les ventes d\'une référence ajoutée par son SKU', async () => {
    apiOk()
    const { wrapper } = await mountView(StatisticsView, { stubs })
    expect(wrapper.text()).toContain('Ajoutez une ou plusieurs références')

    await wrapper.find('[data-test=sku-input] input').setValue('casque-jbl-t510')
    await wrapper.find('[data-test=sku-add]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Casque JBL Tune 510BT [CASQUE-JBL-T510]')
    const charts = wrapper.findAllComponents(stubs.EChart)
    const comparison = charts[charts.length - 1].props('option')
    // Une commande hier (1 article, SKU en minuscules) et une aujourd'hui (2 articles).
    expect(comparison.series[0].data).toEqual([1, 2])
  })

  it('affiche le message d\'erreur de l\'API', async () => {
    api.get.mockRejectedValue({ response: { status: 500, data: { message: 'Magento ne répond pas.' } } })
    const { wrapper } = await mountView(StatisticsView, { stubs })
    expect(wrapper.text()).toContain('Magento ne répond pas.')
    expect(wrapper.text()).not.toContain('Meilleures ventes')
  })
})
