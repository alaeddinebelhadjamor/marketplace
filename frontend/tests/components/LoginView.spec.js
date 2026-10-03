import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mountView } from '../helpers'

vi.mock('@/services/api', async (original) => ({
  ...(await original()),
  api: { get: vi.fn(), post: vi.fn() },
  ensureCsrf: vi.fn().mockResolvedValue(),
}))

const { api } = await import('@/services/api')
const LoginView = (await import('@/views/auth/LoginView.vue')).default

async function fillAndSubmit(wrapper) {
  await wrapper.find('[data-test=identifier] input').setValue('vendeur@example.com')
  await wrapper.find('[data-test=password] input').setValue('MotDePasse123!')
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

describe('Écran de connexion', () => {
  beforeEach(() => vi.clearAllMocks())

  it('n\'envoie rien si les champs sont vides', async () => {
    const { wrapper } = await mountView(LoginView, { path: '/login' })
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.post).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('L\'identifiant est obligatoire.')
  })

  it('affiche le message renvoyé par l\'API (compte en attente)', async () => {
    api.post.mockRejectedValue({ response: { status: 403, data: { message: 'Compte en attente de validation.' } } })
    const { wrapper } = await mountView(LoginView, { path: '/login' })
    await fillAndSubmit(wrapper)

    expect(wrapper.find('[data-test=error]').text()).toBe('Compte en attente de validation.')
  })

  it('redirige vers l\'espace vendeur après connexion', async () => {
    api.post.mockResolvedValue({ data: { id: 6, shop_title: 'B', accessToken: null } })
    api.get.mockResolvedValue({ data: { seller_id: 6, firstname: 'A', lastname: 'B' } })
    const { wrapper, router } = await mountView(LoginView, { path: '/login' })
    const push = vi.spyOn(router, 'push')
    await fillAndSubmit(wrapper)

    expect(api.post).toHaveBeenCalledWith('/api/auth/login', { identifier: 'vendeur@example.com', password: 'MotDePasse123!' })
    expect(push).toHaveBeenCalledWith('/dashboard')
  })

  it('demande le code de double authentification quand il est activé', async () => {
    api.post
      .mockResolvedValueOnce({ data: { two_factor: true, challenge_token: 'jeton-etape' } })
      .mockResolvedValueOnce({ data: { id: 6 } })
    api.get.mockResolvedValue({ data: { seller_id: 6, firstname: 'A', lastname: 'B' } })
    const { wrapper, router } = await mountView(LoginView, { path: '/login' })
    const push = vi.spyOn(router, 'push')
    await fillAndSubmit(wrapper)

    expect(wrapper.find('[data-test=code]').exists()).toBe(true)
    await wrapper.find('[data-test=code] input').setValue('123456')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.post).toHaveBeenLastCalledWith('/api/auth/two-factor-challenge', { challenge_token: 'jeton-etape', code: '123456', recovery_code: null })
    expect(push).toHaveBeenCalledWith('/dashboard')
  })
})
