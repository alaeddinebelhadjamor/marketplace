import { describe, expect, it } from 'vitest'
import { dateRangeError, email, minLength, priceFormError, required, sku } from '@/utils/rules'
import { dt, pct, stripHtml } from '@/utils/format'
import { filenameFrom } from '@/services/files'
import { errorMessage } from '@/services/api'

describe('Règles de formulaire (mêmes règles que l\'API)', () => {
  it('valide le SKU comme le backend', () => {
    expect(sku('SKU-12_3.a')).toBe(true)
    expect(sku('ab')).toMatch(/SKU invalide/)
    expect(sku('a b c')).toMatch(/SKU invalide/)
  })

  it('valide email, longueur minimale et champ obligatoire', () => {
    expect(email('a@b.tn')).toBe(true)
    expect(email('pas-un-email')).toBe('Format email invalide.')
    expect(minLength(6, 'Le mot de passe')('abc')).toMatch(/au moins 6/)
    expect(required('Le nom')('  ')).toBe('Le nom est obligatoire.')
  })

  it('signale une plage de dates inversée (C07)', () => {
    expect(dateRangeError('2026-09-15', '2026-09-01')).toMatch(/corrigez la plage/)
    expect(dateRangeError('2026-09-01', '2026-09-15')).toBeNull()
    expect(dateRangeError('', '2026-09-15')).toBeNull()
  })
})

describe('Fenêtre de modification du prix', () => {
  const original = { price: 100, special_price: null }

  it('bloque un prix promotionnel supérieur ou égal au prix normal', () => {
    expect(priceFormError({ price: 100, hasPromo: true, specialPrice: 120 }, original)).toMatch(/inférieur au prix normal/)
  })

  it('bloque des dates de promotion inversées', () => {
    expect(priceFormError({ price: 100, hasPromo: true, specialPrice: 80, from: '2026-10-10', to: '2026-10-01' }, original)).toMatch(/date de fin/)
  })

  it('désactive l\'enregistrement sans modification', () => {
    expect(priceFormError({ price: 100, hasPromo: false }, original)).toBe('Aucune modification.')
  })

  it('accepte une promotion valide', () => {
    expect(priceFormError({ price: 100, hasPromo: true, specialPrice: 79.9, from: '2026-10-01', to: '2026-10-31' }, original)).toBeNull()
  })
})

describe('Formats et utilitaires', () => {
  it('formate les montants en dinars et les variations', () => {
    expect(dt(1234.5)).toMatch(/1.234,50 DT/)
    expect(pct(12.34)).toBe('+12,3 %')
    expect(pct(null)).toBe('—')
    expect(stripHtml('<p>Bonjour <b>vendeur</b></p>')).toBe('Bonjour vendeur')
  })

  it('lit le nom de fichier de l\'en-tête Content-Disposition', () => {
    expect(filenameFrom({ 'content-disposition': 'attachment; filename="commandes_2026.xlsx"' }, 'x')).toBe('commandes_2026.xlsx')
    expect(filenameFrom({}, 'defaut.pdf')).toBe('defaut.pdf')
  })

  it('extrait le message d\'erreur de l\'API', () => {
    expect(errorMessage({ response: { data: { message: 'Compte refusé.' } } })).toBe('Compte refusé.')
    expect(errorMessage({ response: { data: { error: 'Reclamation not found' } } })).toBe('Reclamation not found')
    expect(errorMessage({})).toBe('Impossible de joindre le serveur.')
  })
})
