import { describe, expect, it } from 'vitest'
import { mountView } from '../helpers'
import KpiCard from '@/components/KpiCard.vue'

describe('Carte indicateur (KPI)', () => {
  it('affiche la valeur, la variation signée et la période précédente', async () => {
    const { wrapper } = await mountView(KpiCard, {
      props: { title: "Chiffre d'affaires", value: '12 450,00 DT', change: 12.5, previous: '11 066,67 DT', icon: 'mdi-cash' },
    })

    expect(wrapper.text()).toContain("Chiffre d'affaires")
    expect(wrapper.text()).toContain('12 450,00 DT')
    expect(wrapper.text()).toContain('+12,5 %')
    expect(wrapper.text()).toContain('vs 11 066,67 DT')
    expect(wrapper.find('.v-chip').classes()).toContain('text-success')
    expect(wrapper.find('.mdi-trending-up').exists()).toBe(true)
  })

  it('suit le sens de la variation quand elle change', async () => {
    const { wrapper } = await mountView(KpiCard, { props: { title: 'Commandes', value: '38', change: -8 } })
    const card = wrapper
    expect(wrapper.find('.mdi-trending-down').exists()).toBe(true)
    expect(wrapper.find('.v-chip').classes()).toContain('text-error')

    await card.setProps({ change: 0 })
    expect(wrapper.find('.mdi-trending-neutral').exists()).toBe(true)

    await card.setProps({ change: null })
    expect(wrapper.text()).toContain('—')
    expect(wrapper.find('.mdi-minus').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('vs')
  })
})
