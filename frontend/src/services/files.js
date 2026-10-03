import { api } from './api'

/** Nom de fichier indiqué par l'en-tête Content-Disposition. */
export function filenameFrom(headers, fallback) {
  const header = headers?.['content-disposition'] || ''
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(header)
  return match ? decodeURIComponent(match[1]) : fallback
}

/** Télécharge une ressource protégée de l'API et l'enregistre sur le poste. */
export async function downloadFile(url, params = {}, fallbackName = 'fichier') {
  const response = await api.get(url, { params, responseType: 'blob' })
  const name = filenameFrom(response.headers, fallbackName)
  const href = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = href
  link.download = name
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(href), 1000)
  return name
}

/** Ouvre une ressource protégée (image, PDF) dans un nouvel onglet. */
export async function openFile(url) {
  // L'onglet est ouvert immédiatement (sinon le navigateur le bloque), puis rempli.
  const tab = window.open('', '_blank')
  const response = await api.get(url, { responseType: 'blob' })
  const href = URL.createObjectURL(response.data)
  if (tab) tab.location.href = href
  else window.location.assign(href)
}

/** Contenu d'un fichier image en base64 (sans le préfixe data:). */
export function toBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(String(reader.result).split(',')[1])
    reader.onerror = reject
    reader.readAsDataURL(file)
  })
}
