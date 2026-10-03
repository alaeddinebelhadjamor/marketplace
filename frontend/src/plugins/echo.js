import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { api } from '@/services/api'

let echo = null

/**
 * Connexion WebSocket à Laravel Reverb (protocole Pusher).
 * L'abonnement aux canaux privés est autorisé par l'API avec la session du
 * vendeur (cookie), via /api/broadcasting/auth.
 * Renvoie null si Reverb n'est pas configuré : l'application se replie alors
 * sur une interrogation périodique.
 */
export function connectEcho() {
  if (echo) return echo
  const key = import.meta.env.VITE_REVERB_APP_KEY
  if (!key) return null

  window.Pusher = Pusher
  const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http'
  echo = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: import.meta.env.VITE_REVERB_HOST || 'localhost',
    wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8091),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT || 8091),
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel) => ({
      authorize: (socketId, callback) => {
        api
          .post('/api/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
          .then((response) => callback(null, response.data))
          .catch((error) => callback(error))
      },
    }),
  })
  return echo
}

export function disconnectEcho() {
  echo?.disconnect()
  echo = null
}
