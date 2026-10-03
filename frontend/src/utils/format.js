const money = new Intl.NumberFormat('fr-TN', { minimumFractionDigits: 2, maximumFractionDigits: 3 })
const integer = new Intl.NumberFormat('fr-TN', { maximumFractionDigits: 0 })

/** Montant en dinars tunisiens : 1 234,50 DT */
export function dt(value) {
  const n = Number(value)
  return `${money.format(Number.isFinite(n) ? n : 0)} DT`
}

export function num(value) {
  return integer.format(Number(value) || 0)
}

/** Variation en % avec signe, ou tiret si non calculable. */
export function pct(value) {
  if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—'
  const n = Number(value)
  return `${n > 0 ? '+' : ''}${n.toLocaleString('fr-FR', { maximumFractionDigits: 1 })} %`
}

export function date(value) {
  if (!value) return '—'
  return new Date(value).toLocaleDateString('fr-FR')
}

export function dateTime(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
}

/** Date AAAA-MM-JJ dans le fuseau local. */
export function isoDay(value = new Date()) {
  const d = new Date(value)
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/** Texte brut d'un contenu HTML (description produit). */
export function stripHtml(html) {
  return String(html || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
}
