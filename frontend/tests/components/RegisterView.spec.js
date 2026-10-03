import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mountView } from '../helpers'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { get: vi.fn(), post: vi.fn() },
  ensureCsrf: vi.fn().mockResolvedValue(),
}))

const { api } = await import('@/services/api')
const RegisterView = (await import('@/views/auth/RegisterView.vue')).default

function fill(vm, extra = {}) {
  Object.assign(vm.data, {
    firstname: 'Ala', lastname: 'Test', email: 'ala@example.com', password: 'MotDePasse123!', contact_number: '20000000',
    shop_title: 'Boutique Ala', address: 'Rue 1', zipcode: '1000', governorate: 'Tunis', ...extra,
  })
}

describe('Formulaire d\'inscription', () => {
  it('rend le matricule fiscal obligatoire seulement si une patente est déclarée', async () => {
    const { wrapper } = await mountView(RegisterView, { path: '/register' })
    expect(wrapper.find('[data-test=tax-id]').exists()).toBe(false)

    fill(wrapper.vm, { has_patent: true })
    await flushPromises()
    expect(wrapper.find('[data-test=tax-id]').exists()).toBe(true)

    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(api.post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Le matricule fiscal est obligatoire.')
  })

  it('envoie l\'inscription et affiche le message d\'attente de validation', async () => {
    api.post.mockResolvedValue({ data: { message: 'Inscription réussie ! En attente de validation.' } })
    const { wrapper } = await mountView(RegisterView, { path: '/register' })
    fill(wrapper.vm)
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/auth/register', expect.objectContaining({ email: 'ala@example.com', has_patent: 0 }))
    expect(wrapper.find('[data-test=success]').text()).toContain('Bienvenue chez Mytek')
  })

  it('affiche le conflit renvoyé par l\'API (409)', async () => {
    api.post.mockRejectedValue({ response: { status: 409, data: { message: 'Email déjà utilisé.' } } })
    const { wrapper } = await mountView(RegisterView, { path: '/register' })
    fill(wrapper.vm)
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test=error]').text()).toBe('Email déjà utilisé.')
  })
})
