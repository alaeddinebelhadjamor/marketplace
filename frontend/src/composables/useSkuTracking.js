import { ref } from 'vue'

export const MAX_TRACKED = 5
export const SERIES_COLORS = ['#2196F3', '#4CAF50', '#FF9800', '#9C27B0', '#00BCD4']

/**
 * Références suivies dans le graphique de comparaison (tab. 2.10) :
 * au plus 5, sans doublon, avec un message si la limite est atteinte.
 */
export function useSkuTracking() {
  const tracked = ref([])
  const message = ref('')

  function add(rawSku, name) {
    message.value = ''
    const sku = String(rawSku || '').trim().toUpperCase()
    if (!sku) return false
    if (tracked.value.some((t) => t.sku === sku)) {
      message.value = 'Cette référence est déjà suivie.'
      return false
    }
    if (tracked.value.length >= MAX_TRACKED) {
      message.value = `Maximum ${MAX_TRACKED} produits en comparaison simultanée.`
      return false
    }
    const used = new Set(tracked.value.map((t) => t.color))
    const color = SERIES_COLORS.find((c) => !used.has(c))
    tracked.value.push({ sku, name: name || sku, color })
    return true
  }

  function remove(sku) {
    tracked.value = tracked.value.filter((t) => t.sku !== sku)
    message.value = ''
  }

  return { tracked, message, add, remove }
}
