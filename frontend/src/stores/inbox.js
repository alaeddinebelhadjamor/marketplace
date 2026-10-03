import { defineStore } from 'pinia'
import { api } from '@/services/api'
import { connectEcho, disconnectEcho } from '@/plugins/echo'

const FALLBACK_POLL_MS = 60000

/**
 * Boîte du vendeur : réclamations et notifications.
 *
 * La v1 interrogeait l'API toutes les 30 s. La v2 reçoit les nouveautés en
 * temps réel (Reverb) ; l'interrogation ne sert plus que de secours, toutes
 * les 60 s, si la connexion WebSocket est impossible.
 */
export const useInboxStore = defineStore('inbox', {
  state: () => ({
    items: [],
    loaded: false,
    realtime: false,
    lastEvent: null,
    pollTimer: null,
  }),

  getters: {
    notifications: (s) => s.items.filter((r) => r.type === 0),
    unreadNotifications: (s) => s.items.filter((r) => r.type === 0 && r.vendeur_viewed === 0),
    reclamations: (s) => s.items.filter((r) => r.type !== 0),
    /** Badge du menu : réclamations ouvertes avec une réponse non lue. */
    unreadReclamations: (s) => s.items.filter((r) => r.type === 1 && r.vendeur_viewed === 0).length,
  },

  actions: {
    async refresh() {
      const { data } = await api.get('/api/reclamations/seller')
      this.items = data
      this.loaded = true
    },

    async markSeen(id) {
      await api.put(`/api/reclamations/${id}/seen-by-seller`)
      const item = this.items.find((r) => r.id === id)
      if (item) item.vendeur_viewed = 1
    },

    async markAllNotificationsSeen() {
      await Promise.allSettled(this.unreadNotifications.map((n) => this.markSeen(n.id)))
    },

    /** Démarre la réception temps réel pour le vendeur connecté. */
    async start(sellerId) {
      await this.refresh().catch(() => {})
      const echo = connectEcho()
      if (echo) {
        echo
          .private(`seller.${sellerId}`)
          .listen('.inbox.updated', (event) => {
            this.lastEvent = event
            this.refresh().catch(() => {})
          })
        const connection = echo.connector?.pusher?.connection
        connection?.bind('connected', () => {
          this.realtime = true
          this.stopPolling()
        })
        connection?.bind('unavailable', () => {
          this.realtime = false
          this.startPolling()
        })
      } else {
        this.startPolling()
      }
    },

    startPolling() {
      if (this.pollTimer) return
      this.pollTimer = setInterval(() => this.refresh().catch(() => {}), FALLBACK_POLL_MS)
    },

    stopPolling() {
      clearInterval(this.pollTimer)
      this.pollTimer = null
    },

    stop() {
      this.stopPolling()
      disconnectEcho()
      this.$reset()
    },
  },
})
