/**
 * Règles de validation des formulaires, identiques à celles de l'API :
 * le formulaire bloque l'envoi avant même l'appel au serveur.
 */
export const SKU_PATTERN = /^[A-Za-z0-9._-]{3,64}$/
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export const required = (label = 'Ce champ') => (v) =>
  (v !== null && v !== undefined && String(v).trim() !== '') || `${label} est obligatoire.`

export const email = (v) => !v || EMAIL_PATTERN.test(String(v).trim()) || 'Format email invalide.'

export const minLength = (n, label = 'Ce champ') => (v) => !v || String(v).length >= n || `${label} doit contenir au moins ${n} caractères.`

export const sku = (v) => !v || SKU_PATTERN.test(String(v)) || "SKU invalide : 3 à 64 caractères, lettres, chiffres, '.', '_' ou '-' uniquement."

export const positive = (label = 'Le prix') => (v) =>
  v === null || v === undefined || v === '' || Number(v) > 0 || `${label} doit être strictement positif.`

/**
 * Plage de dates : null si valide, sinon le message à afficher (point C07 :
 * le vendeur est invité à corriger une date de début postérieure à la fin).
 */
export function dateRangeError(from, to) {
  if (from && to && from > to) return 'La date de début doit précéder la date de fin : corrigez la plage de dates.'
  return null
}

/**
 * Bouton « Enregistrer » de la fenêtre de prix : désactivé tant que la saisie
 * est incomplète, incohérente ou identique à l'existant.
 */
export function priceFormError({ price, hasPromo, specialPrice, from, to }, original = {}) {
  const p = Number(price)
  if (!price || !(p > 0)) return 'Saisissez un prix strictement positif.'
  if (hasPromo) {
    const s = Number(specialPrice)
    if (!specialPrice || !(s > 0)) return 'Saisissez le prix promotionnel.'
    if (s >= p) return 'Le prix promotionnel doit être inférieur au prix normal.'
    if (from && to && from > to) return 'La date de fin de promotion doit suivre la date de début.'
  }
  const unchanged =
    p === Number(original.price) &&
    hasPromo === Boolean(original.special_price) &&
    (!hasPromo ||
      (Number(specialPrice) === Number(original.special_price) &&
        (from || null) === (original.special_from_date?.slice(0, 10) || null) &&
        (to || null) === (original.special_to_date?.slice(0, 10) || null)))
  return unchanged ? 'Aucune modification.' : null
}
