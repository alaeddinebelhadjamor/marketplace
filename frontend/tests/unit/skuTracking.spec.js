import { describe, expect, it } from 'vitest'
import { MAX_TRACKED, useSkuTracking } from '@/composables/useSkuTracking'

describe('Comparaison de références (tab. 2.10)', () => {
  it('suit au plus 5 références et affiche un message au-delà', () => {
    const t = useSkuTracking()
    for (let i = 1; i <= MAX_TRACKED; i++) expect(t.add(`sku-${i}`)).toBe(true)

    expect(t.add('SKU-6')).toBe(false)
    expect(t.message.value).toBe('Maximum 5 produits en comparaison simultanée.')
    expect(t.tracked.value).toHaveLength(5)
  })

  it('normalise en majuscules, refuse les doublons et réattribue les couleurs libérées', () => {
    const t = useSkuTracking()
    t.add('abc', 'Clavier')
    expect(t.add('ABC')).toBe(false)
    expect(t.tracked.value[0]).toMatchObject({ sku: 'ABC', name: 'Clavier' })

    t.add('DEF')
    const color = t.tracked.value[0].color
    t.remove('ABC')
    t.add('GHI')
    expect(t.tracked.value.find((x) => x.sku === 'GHI').color).toBe(color)
  })
})
