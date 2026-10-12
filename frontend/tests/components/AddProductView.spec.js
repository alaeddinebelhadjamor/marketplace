import { describe, expect, it, vi } from 'vitest'
import { VFileInput, VNumberInput } from 'vuetify/components'
import { flushPromises, mountView } from '../helpers'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { post: vi.fn() },
}))
vi.mock('@/services/files', () => ({ toBase64: vi.fn(async (f) => `BASE64(${f.name})`) }))

const { api } = await import('@/services/api')
const AddProductView = (await import('@/views/AddProductView.vue')).default

async function fill(wrapper, { mainImage = new File(['img'], 'casque.jpg', { type: 'image/jpeg' }) } = {}) {
  await wrapper.find('[data-test=sku] input').setValue('CASQUE-JBL-T510')
  await wrapper.findAll('input[type=text]').find((i) => i.element.closest('.v-text-field').textContent.includes('Nom')).setValue('Casque JBL Tune 510BT')
  const [price, weight] = wrapper.findAllComponents(VNumberInput)
  price.vm.$emit('update:modelValue', 129)
  weight.vm.$emit('update:modelValue', 0.16)
  await wrapper.findAll('textarea')[0].setValue('Casque Bluetooth pliable')
  const [main, extra] = wrapper.findAllComponents(VFileInput)
  if (mainImage) main.vm.$emit('update:modelValue', mainImage)
  extra.vm.$emit('update:modelValue', [new File(['img2'], 'casque-profil.png', { type: 'image/png' })])
  await flushPromises()
}

describe('Ajout d\'un produit', () => {
  it('refuse un formulaire incomplet sans appeler l\'API', async () => {
    const { wrapper } = await mountView(AddProductView)

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain("L'image principale est obligatoire.")
    expect(wrapper.findAll('.v-messages__message').length).toBeGreaterThan(3)
  })

  it('refuse un SKU au format invalide', async () => {
    const { wrapper } = await mountView(AddProductView)
    await fill(wrapper)
    await wrapper.find('[data-test=sku] input').setValue('sku avec espaces')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('SKU invalide')
    expect(api.post).not.toHaveBeenCalled()
  })

  it('soumet le produit avec ses images et confirme l\'envoi', async () => {
    api.post.mockResolvedValue({ data: { sku: 'CASQUE-JBL-T510' } })
    const { wrapper } = await mountView(AddProductView)
    await fill(wrapper)

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.post).toHaveBeenCalledTimes(1)
    const [url, { product }] = api.post.mock.calls[0]
    expect(url).toBe('/api/magento/product/add')
    expect(product).toMatchObject({
      sku: 'CASQUE-JBL-T510', name: 'Casque JBL Tune 510BT', price: 129, weight: 0.16,
      extension_attributes: { stock_item: { qty: 10 } },
    })
    expect(product.custom_attributes[0]).toEqual({ attribute_code: 'short_description', value: 'Casque Bluetooth pliable' })
    expect(product.media_gallery_entries).toEqual([
      { label: 'Image principale', content: { base64_encoded_data: 'BASE64(casque.jpg)', type: 'image/jpeg', name: 'casque.jpg' } },
      { label: 'casque-profil.png', content: { base64_encoded_data: 'BASE64(casque-profil.png)', type: 'image/png', name: 'casque-profil.png' } },
    ])
    expect(wrapper.text()).toContain('Produit « Casque JBL Tune 510BT » soumis')
  })

  it('affiche l\'erreur renvoyée par l\'API', async () => {
    api.post.mockRejectedValue({ response: { status: 409, data: { message: 'Ce SKU existe déjà dans le catalogue.' } } })
    const { wrapper } = await mountView(AddProductView)
    await fill(wrapper)

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test=error]').text()).toBe('Ce SKU existe déjà dans le catalogue.')
  })
})
