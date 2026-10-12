import { beforeEach, describe, expect, it, vi } from 'vitest'
import { byText, click, flushPromises, mountView } from '../helpers'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const { api } = await import('@/services/api')
const AccountView = (await import('@/views/AccountView.vue')).default

const seller = {
  seller_id: 12, firstname: 'Amira', lastname: 'Trabelsi', email: 'amira@techshop.tn', contact_number: '98 123 456',
  shop_title: 'TechShop Sfax', company: 'TechShop SARL', address: '12 avenue Habib Bourguiba', governorate: 'Sfax',
  zipcode: '3000', has_patent: true, tax_id: '1234567A/M/000',
}
let twoFactor

function apiOk() {
  api.get.mockImplementation((url) => {
    if (url === '/api/profile') return Promise.resolve({ data: seller })
    if (url === '/api/profile/two-factor') return Promise.resolve({ data: twoFactor })
    if (url === '/api/profile/activity') {
      return Promise.resolve({ data: [
        { id: 3, event: 'product_price_updated', description: 'Prix modifié', created_at: '2026-10-11T10:00:00Z', ip: '197.0.0.10', details: { sku: 'CASQUE-JBL-T510' } },
        { id: 2, event: 'login', description: 'Connexion', created_at: '2026-10-11T09:58:00Z', ip: '197.0.0.10', details: null },
      ] })
    }
    return Promise.reject(new Error(url))
  })
}

const tab = (label) => click(byText(label, '.v-tab'))

describe('Mon compte', () => {
  beforeEach(() => {
    twoFactor = { enabled: false, recovery_codes_left: 0 }
    vi.clearAllMocks()
  })

  it('recharge et affiche le profil du vendeur', async () => {
    apiOk()
    const { wrapper } = await mountView(AccountView)

    expect(api.get).toHaveBeenCalledWith('/api/profile')
    expect(wrapper.text()).toContain('TechShop Sfax · amira@techshop.tn')
    const rows = Object.fromEntries(wrapper.findAll('tbody tr').map((r) => [r.find('th').text(), r.find('td').text()]))
    expect(rows).toMatchObject({ Prénom: 'Amira', Gouvernorat: 'Sfax', Patente: 'Oui', 'Matricule fiscal': '1234567A/M/000' })
  })

  it('change le mot de passe après vérification de la confirmation', async () => {
    apiOk()
    api.put.mockResolvedValue({ data: { message: 'Mot de passe modifié.' } })
    const { wrapper } = await mountView(AccountView)
    await tab('Mot de passe')

    const [current, next, confirm] = wrapper.findAll('input[type=password]')
    await current.setValue('ancienMdp1')
    await next.setValue('nouveauMdp2')
    await confirm.setValue('autreChose')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Les mots de passe ne correspondent pas.')
    expect(api.put).not.toHaveBeenCalled()

    await confirm.setValue('nouveauMdp2')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(api.put).toHaveBeenCalledWith('/api/profile/change-password', { currentPassword: 'ancienMdp1', newPassword: 'nouveauMdp2' })
    expect(wrapper.text()).toContain('Mot de passe modifié.')
  })

  it('active la double authentification (QR code, codes de secours, confirmation)', async () => {
    apiOk()
    api.post.mockImplementation((url) => Promise.resolve({ data: url === '/api/profile/two-factor'
      ? { secret: 'JBSWY3DPEHPK3PXP', qr_svg: '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/><script>alert(1)</script></svg>', recovery_codes: ['ab12-cd34', 'ef56-gh78'] }
      : {} }))
    const { wrapper } = await mountView(AccountView)
    await tab('Double authentification')

    expect(api.get).toHaveBeenCalledWith('/api/profile/two-factor')
    expect(wrapper.text()).toContain('Désactivée')
    const activate = () => wrapper.findAll('button').find((b) => b.text().includes('Activer la double'))
    expect(activate().attributes('disabled')).toBeDefined()

    await wrapper.find('input[type=password]').setValue('monMotDePasse')
    await activate().trigger('click')
    await flushPromises()
    expect(api.post).toHaveBeenCalledWith('/api/profile/two-factor', { password: 'monMotDePasse' })
    expect(wrapper.text()).toContain('JBSWY3DPEHPK3PXP')
    expect(wrapper.find('svg rect').exists()).toBe(true)
    expect(wrapper.html()).not.toContain('<script')
    expect(wrapper.text()).toContain('ab12-cd34')

    twoFactor = { enabled: true, recovery_codes_left: 8 }
    await wrapper.find('input[inputmode=numeric]').setValue('123456')
    await wrapper.findAll('button').find((b) => b.text() === 'Activer').trigger('click')
    await flushPromises()
    expect(api.post).toHaveBeenCalledWith('/api/profile/two-factor/confirm', { code: '123456' })
    expect(wrapper.text()).toContain('Double authentification activée.')
    expect(wrapper.text()).toContain('8 code(s) de secours restant(s)')
  })

  it('désactive la double authentification et affiche les refus de l\'API', async () => {
    twoFactor = { enabled: true, recovery_codes_left: 5 }
    apiOk()
    const { wrapper } = await mountView(AccountView)
    await tab('Double authentification')
    await wrapper.find('input[type=password]').setValue('mauvais')

    api.delete.mockRejectedValueOnce({ response: { status: 422, data: { message: 'Mot de passe incorrect.' } } })
    await wrapper.findAll('button').find((b) => b.text() === 'Désactiver').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Mot de passe incorrect.')

    api.delete.mockResolvedValueOnce({ data: {} })
    twoFactor = { enabled: false, recovery_codes_left: 0 }
    await wrapper.findAll('button').find((b) => b.text() === 'Désactiver').trigger('click')
    await flushPromises()
    expect(api.delete).toHaveBeenLastCalledWith('/api/profile/two-factor', { data: { password: 'mauvais' } })
    expect(wrapper.text()).toContain('Double authentification désactivée.')
  })

  it('génère de nouveaux codes de secours', async () => {
    twoFactor = { enabled: true, recovery_codes_left: 1 }
    apiOk()
    api.post.mockResolvedValue({ data: { recovery_codes: ['zz99-yy88'] } })
    const { wrapper } = await mountView(AccountView)
    await tab('Double authentification')
    await wrapper.find('input[type=password]').setValue('monMotDePasse')

    await wrapper.findAll('button').find((b) => b.text().includes('Nouveaux codes')).trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/profile/two-factor/recovery-codes', { password: 'monMotDePasse' })
    expect(wrapper.text()).toContain('zz99-yy88')
  })

  it('affiche le journal d\'activité', async () => {
    apiOk()
    const { wrapper } = await mountView(AccountView)
    await tab('Activité')

    expect(api.get).toHaveBeenCalledWith('/api/profile/activity')
    expect(wrapper.text()).toContain('Prix modifié')
    expect(wrapper.text()).toContain('IP 197.0.0.10')
    expect(wrapper.text()).toContain('SKU CASQUE-JBL-T510')
    expect(wrapper.find('.mdi-tag-edit-outline').exists()).toBe(true)
  })
})
