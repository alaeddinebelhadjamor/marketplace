import { defineStore } from 'pinia'
import { api, ensureCsrf } from '@/services/api'

/**
 * Session du vendeur. La session vit dans un cookie HttpOnly posé par l'API :
 * le store ne garde que le profil, jamais de jeton.
 */
export const useAuthStore = defineStore('auth', {
  state: () => ({
    seller: null,
    checked: false,
    challengeToken: null,
  }),

  getters: {
    isAuthenticated: (state) => state.seller !== null,
    displayName: (state) => (state.seller ? `${state.seller.firstname} ${state.seller.lastname}` : ''),
  },

  actions: {
    /** Charge le profil si une session existe (au démarrage de l'application). */
    async restore() {
      if (this.checked) return this.seller
      try {
        const { data } = await api.get('/api/profile')
        this.seller = data
      } catch {
        this.seller = null
      } finally {
        this.checked = true
      }
      return this.seller
    },

    async register(payload) {
      await ensureCsrf()
      const { data } = await api.post('/api/auth/register', payload)
      return data
    },

    /**
     * Connexion. Renvoie { twoFactor: true } si un code de double
     * authentification est attendu.
     */
    async login(identifier, password) {
      await ensureCsrf(true)
      const { data } = await api.post('/api/auth/login', { identifier, password })
      if (data.two_factor) {
        this.challengeToken = data.challenge_token
        return { twoFactor: true }
      }
      await this.afterLogin()
      return { twoFactor: false }
    },

    async verifyTwoFactor({ code, recoveryCode }) {
      await api.post('/api/auth/two-factor-challenge', {
        challenge_token: this.challengeToken,
        code: code || null,
        recovery_code: recoveryCode || null,
      })
      this.challengeToken = null
      await this.afterLogin()
    },

    async afterLogin() {
      const { data } = await api.get('/api/profile')
      this.seller = data
      this.checked = true
    },

    async logout() {
      try {
        await api.post('/api/auth/logout')
      } catch {
        // session déjà expirée : rien à faire côté serveur
      }
      this.clear()
    },

    clear() {
      this.seller = null
      this.challengeToken = null
      this.checked = true
    },
  },
})
