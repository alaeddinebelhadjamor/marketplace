import { afterEach, describe, expect, it, vi } from 'vitest'
import { VFileInput } from 'vuetify/components'
import { flushPromises, mountView } from '../helpers'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@/services/files', () => ({ downloadFile: vi.fn() }))

const { api } = await import('@/services/api')
const { downloadFile } = await import('@/services/files')
const ImportProductsView = (await import('@/views/ImportProductsView.vue')).default

const doneImport = {
  id: 18,
  original_name: 'catalogue_octobre.xlsx',
  status: 'done',
  created_at: '2026-10-11T14:20:00Z',
  total_rows: 120,
  success_rows: 117,
  failed_rows: 3,
  failure_reason: null,
  errors: [
    { row: 14, sku: 'CLAV-RGB-01', message: 'Ce SKU existe déjà.' },
    { row: 52, sku: '', message: 'Le SKU est obligatoire.' },
    { row: 87, sku: 'ECRAN-27', message: 'Le prix doit être positif.' },
  ],
}

describe('Import de produits en masse', () => {
  afterEach(() => vi.useRealTimers())

  it('affiche l\'historique et le détail des lignes en erreur', async () => {
    api.get.mockResolvedValue({ data: [doneImport, { ...doneImport, id: 17, original_name: 'vieux.csv', status: 'failed', failure_reason: 'Fichier illisible.', errors: [], failed_rows: 0 }] })
    const { wrapper } = await mountView(ImportProductsView)

    expect(api.get).toHaveBeenCalledWith('/api/magento/products/imports')
    expect(wrapper.text()).toContain('catalogue_octobre.xlsx')
    expect(wrapper.text()).toContain('Terminé')
    expect(wrapper.text()).toContain('Échec')

    await wrapper.find('.v-expansion-panel-title').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('120 ligne(s) : 117 soumise(s), 3 en erreur')
    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
    expect(wrapper.text()).toContain('Ce SKU existe déjà.')

    await wrapper.findAll('button').find((b) => b.text().includes('Rapport complet')).trigger('click')
    expect(downloadFile).toHaveBeenCalledWith('/api/magento/products/imports/18/report', {}, 'rapport_import_18.xlsx')
  })

  it('télécharge le modèle de fichier', async () => {
    api.get.mockResolvedValue({ data: [] })
    const { wrapper } = await mountView(ImportProductsView)
    expect(wrapper.text()).toContain('Aucun import pour le moment.')

    await wrapper.findAll('button').find((b) => b.text().includes('Télécharger le modèle')).trigger('click')
    expect(downloadFile).toHaveBeenCalledWith('/api/magento/products/imports/template', {}, 'modele_import_produits.xlsx')
  })

  it('envoie le fichier puis suit l\'import en cours jusqu\'à la fin', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    api.get.mockResolvedValueOnce({ data: [] })
    api.post.mockResolvedValue({ data: { id: 19 } })
    const { wrapper } = await mountView(ImportProductsView)
    const launch = () => wrapper.findAll('button').find((b) => b.text().includes("Lancer l'import"))
    expect(launch().attributes('disabled')).toBeDefined()

    const file = new File(['sku;name;price'], 'nouveaux_produits.csv', { type: 'text/csv' })
    wrapper.findComponent(VFileInput).vm.$emit('update:modelValue', [file])
    await flushPromises()
    api.get.mockResolvedValueOnce({ data: [{ ...doneImport, id: 19, original_name: 'nouveaux_produits.csv', status: 'processing', errors: [] }] })
    await launch().trigger('click')
    await flushPromises()

    const [url, body] = api.post.mock.calls[0]
    expect(url).toBe('/api/magento/products/imports')
    expect(body.get('file').name).toBe('nouveaux_produits.csv')
    expect(wrapper.text()).toContain('En cours')

    // Rafraîchissement automatique toutes les 3 secondes tant que l'import tourne.
    api.get.mockResolvedValueOnce({ data: [{ ...doneImport, id: 19, original_name: 'nouveaux_produits.csv' }] })
    await vi.advanceTimersByTimeAsync(3000)
    await flushPromises()
    expect(api.get).toHaveBeenCalledTimes(3)
    expect(wrapper.text()).toContain('Terminé')
  })

  it('affiche le refus de l\'API', async () => {
    api.get.mockResolvedValue({ data: [] })
    api.post.mockRejectedValue({ response: { status: 422, data: { message: 'Le fichier dépasse 500 lignes.' } } })
    const { wrapper } = await mountView(ImportProductsView)

    wrapper.findComponent(VFileInput).vm.$emit('update:modelValue', [new File(['x'], 'trop_gros.xlsx')])
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text().includes("Lancer l'import")).trigger('click')
    await flushPromises()

    expect(wrapper.find('.v-alert').text()).toBe('Le fichier dépasse 500 lignes.')
  })
})
