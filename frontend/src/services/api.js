import axios from 'axios'

/**
 * Client HTTP unique de l'application.
 *
 * - URL de l'API lue dans VITE_API_URL (jamais codée en dur).
 * - Authentification par cookie de session HttpOnly (Sanctum SPA) : aucun
 *   jeton n'est stocké dans le navigateur, contrairement à la v1.
 * - Protection CSRF : le cookie XSRF-TOKEN est renvoyé dans l'en-tête X-XSRF-TOKEN.
 */
export const API_URL = (import.meta.env.VITE_API_URL || '').replace(/\/$/, '')

export const api = axios.create({
  baseURL: API_URL,
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  timeout: 30000,
})

let csrfReady = false

/** Demande le cookie CSRF avant la première requête qui modifie des données. */
export async function ensureCsrf(force = false) {
  if (csrfReady && !force) return
  await api.get('/sanctum/csrf-cookie')
  csrfReady = true
}

api.interceptors.request.use(async (config) => {
  const method = (config.method || 'get').toLowerCase()
  if (!['get', 'head', 'options'].includes(method) && !config.url?.includes('/sanctum/csrf-cookie')) {
    await ensureCsrf()
  }
  return config
})

let onUnauthenticated = () => {}

/** Action à exécuter quand la session a expiré (branchée par le store d'authentification). */
export function setUnauthenticatedHandler(handler) {
  onUnauthenticated = handler
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error.response?.status
    const config = error.config || {}

    // Jeton CSRF expiré : on le renouvelle une fois et on rejoue la requête.
    if (status === 419 && !config._retried) {
      config._retried = true
      await ensureCsrf(true)
      return api(config)
    }

    const isAuthRoute = config.url?.includes('/api/auth/')
    if (!isAuthRoute && (status === 401 || (status === 403 && /Token|Compte non validé/.test(error.response?.data?.message || '')))) {
      onUnauthenticated()
    }

    return Promise.reject(error)
  },
)

/** Message d'erreur lisible renvoyé par l'API, sinon message par défaut. */
export function errorMessage(error, fallback = 'Une erreur est survenue. Réessayez.') {
  if (!error?.response) return 'Impossible de joindre le serveur.'
  const data = error.response.data
  if (data instanceof Blob) return fallback
  return data?.message || data?.error || fallback
}

/** Erreurs de validation par champ ({ champ: [messages] }). */
export function fieldErrors(error) {
  return error?.response?.data?.errors || {}
}
