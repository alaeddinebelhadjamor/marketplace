import { beforeEach, describe, expect, it, vi } from 'vitest'
import { byText, click, flushPromises, mountView, type } from '../helpers'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { get: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const { api } = await import('@/services/api')
const ProductsView = (await import('@/views/ProductsView.vue')).default

const products = () => [
  {
    sku: 'CASQUE-JBL-T510', name: 'Casque JBL Tune 510BT', price: 129, special_price: 119, status: 1,
    special_from_date: '2026-10-01 00:00:00', special_to_date: '2026-10-31 00:00:00',
    short_description: '<p>Casque <strong>Bluetooth</strong> pliable<script>alert(1)</script></p>', image: null,
  },
  { sku: 'SOURIS-LOGI-M185', name: 'Souris Logitech M185', price: 39.9, special_price: null, status: 1, short_description: 'Souris sans fil', image: null },
]
const page = (list = products(), extra = {}) => ({ data: { products: list, total: list.length, totalPages: 1, ...extra } })

describe('Mes produits', () => {
  beforeEach(() => {
    api.get.mockReset()
    window.open = vi.fn()
  })

  it('affiche les produits validés avec prix, promotion et description nettoyée', async () => {
    api.get.mockResolvedValue(page())
    const { wrapper } = await mountView(ProductsView)

    expect(api.get).toHaveBeenCalledWith('/api/magento/products', { params: { page: 1, pageSize: 20, search: undefined } })
    expect(wrapper.text()).toContain('2 produit(s) validé(s)')
    const cards = wrapper.findAll('.v-col .v-card')
    expect(cards[0].text()).toContain('[CASQUE-JBL-T510]')
    expect(cards[0].text()).toMatch(/119,00 DT\s*129,00 DT/)
    expect(cards[0].find('strong').text()).toBe('Bluetooth')
    expect(cards[0].html()).not.toContain('<script')
    expect(cards[1].text()).toContain('39,90 DT')
  })

  it('recherche par nom ou SKU et explique un résultat vide', async () => {
    api.get.mockResolvedValue(page())
    const { wrapper } = await mountView(ProductsView)
    api.get.mockResolvedValue(page([]))

    await wrapper.find('input[type=text]').setValue('  clavier ')
    await wrapper.find('input[type=text]').trigger('keyup', { key: 'Enter' })
    await flushPromises()

    expect(api.get).toHaveBeenLastCalledWith('/api/magento/products', { params: { page: 1, pageSize: 20, search: 'clavier' } })
    expect(wrapper.text()).toContain('Aucun produit ne correspond à « clavier ».')
  })

  it('bascule sur les produits en attente et explique leur statut', async () => {
    api.get.mockResolvedValue(page())
    const { wrapper } = await mountView(ProductsView)
    api.get.mockResolvedValue(page([{ ...products()[1], sku: 'ECRAN-27', name: 'Écran Samsung 27"', status: 2 }]))

    await click(byText('Produits en attente', '.v-tab'))

    expect(api.get).toHaveBeenLastCalledWith('/api/magento/products/pending', expect.anything())
    expect(wrapper.text()).toContain('1 produit(s) en attente de validation')
    expect(wrapper.text()).toContain('En attente de validation')
    // Pas de modification de prix pour un produit non validé.
    expect(wrapper.find('[aria-label^="Modifier le prix"]').exists()).toBe(false)

    await wrapper.find('.v-card-title a').trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('est en cours de validation')
    expect(window.open).not.toHaveBeenCalled()
  })

  it('ouvre la fiche d\'un produit validé sur le site', async () => {
    const tab = { location: { href: '' }, close: vi.fn() }
    window.open.mockReturnValue(tab)
    api.get.mockResolvedValueOnce(page()).mockResolvedValueOnce({ data: { url: 'https://www.mytek.tn/casque-jbl-tune-510bt.html' } })
    const { wrapper } = await mountView(ProductsView)

    await wrapper.find('.v-card-title a').trigger('click')
    await flushPromises()

    expect(api.get).toHaveBeenLastCalledWith('/api/magento/products/url-key', { params: { sku: 'CASQUE-JBL-T510' } })
    expect(tab.location.href).toBe('https://www.mytek.tn/casque-jbl-tune-510bt.html')
  })

  it('supprime un produit après confirmation', async () => {
    api.get.mockResolvedValue(page())
    api.delete.mockResolvedValue({ data: {} })
    const { wrapper } = await mountView(ProductsView)

    await wrapper.find('[aria-label="Supprimer Souris Logitech M185"]').trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('Cette action est irréversible')
    await click(byText('Oui, supprimer'))

    expect(api.delete).toHaveBeenCalledWith('/api/magento/product/delete', { data: { sku: 'SOURIS-LOGI-M185' } })
    expect(wrapper.text()).toContain('1 produit(s) validé(s)')
    expect(wrapper.text()).not.toContain('Souris Logitech M185')
    expect(document.body.textContent).toContain('Produit supprimé avec succès')
  })

  it('modifie le prix normal d\'un produit', async () => {
    api.get.mockResolvedValue(page())
    api.put.mockResolvedValue({ data: {} })
    const { wrapper } = await mountView(ProductsView)

    await wrapper.find('[aria-label="Modifier le prix de Souris Logitech M185"]').trigger('click')
    await flushPromises()
    const priceInput = document.querySelector('.v-dialog input')
    expect(priceInput.value).toBe('39.900')
    await type(priceInput, '34.5')
    priceInput.dispatchEvent(new Event('blur'))
    await flushPromises()
    await click(byText('Enregistrer'))

    expect(api.put).toHaveBeenCalledWith('/api/magento/product/price', {
      sku: 'SOURIS-LOGI-M185', price: 34.5, special_price: null, special_from_date: null, special_to_date: null,
    })
    expect(wrapper.text()).toContain('34,50 DT')
  })

  it('affiche l\'erreur de chargement', async () => {
    api.get.mockRejectedValue({ response: { status: 502, data: { message: 'Catalogue Magento indisponible.' } } })
    const { wrapper } = await mountView(ProductsView)
    expect(wrapper.find('.v-alert').text()).toBe('Catalogue Magento indisponible.')
    expect(wrapper.text()).toContain('Aucun produit validé pour le moment.')
  })
})
