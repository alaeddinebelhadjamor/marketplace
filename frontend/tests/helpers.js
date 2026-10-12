import { h } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as components from 'vuetify/components'
import * as directives from 'vuetify/directives'

const NAMED_ROUTES = ['login', 'register', 'forgot-password', 'statistics', 'products', 'add-product', 'import',
  'sales', 'statements', 'reclamations', 'account']

/**
 * Monte un composant avec Vuetify, Pinia et un routeur en mémoire.
 * - app : enveloppe le composant dans <v-app> (layouts avec v-app-bar / v-main) ;
 * - before(pinia) : prépare les stores avant le montage ;
 * - stubs : composants remplacés (graphiques ECharts, inutilisables sous jsdom).
 */
export async function mountView(component, { routes = [], path = '/', props = {}, app = false, before, stubs = {} } = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)
  before?.(pinia)
  const vuetify = createVuetify({ components, directives })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:p(.*)*', name: 'any', component: { template: '<div />' } }, ...routes,
      ...NAMED_ROUTES.map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } }))],
  })
  router.push(path)
  await router.isReady()
  const root = app ? { render: () => h(components.VApp, null, () => h(component, props)) } : component
  const wrapper = mount(root, { props: app ? {} : props, global: { plugins: [pinia, vuetify, router], stubs }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router, pinia }
}

/** Bouton (ou élément) dont le texte contient `text`, y compris dans les fenêtres téléportées. */
export function byText(text, selector = 'button, .v-list-item, .v-tab, .v-chip') {
  return [...document.querySelectorAll(selector)].find((el) => el.textContent.includes(text))
}

/** Clique sur un élément du DOM puis attend la fin des promesses. */
export async function click(el) {
  if (!el) throw new Error('Élément introuvable')
  el.click()
  await flushPromises()
}

/** Saisit une valeur dans un champ du DOM (champs téléportés dans une fenêtre). */
export async function type(el, value) {
  el.value = value
  el.dispatchEvent(new Event('input'))
  await flushPromises()
}

/** Réponse simulée de l'API selon l'adresse appelée. */
export function byUrl(responses) {
  return (url) => (url in responses ? Promise.resolve({ data: responses[url] }) : Promise.reject(new Error(`URL inattendue : ${url}`)))
}

export { flushPromises }
