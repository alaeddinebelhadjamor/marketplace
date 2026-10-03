import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as components from 'vuetify/components'
import * as directives from 'vuetify/directives'

/** Monte un composant avec Vuetify, Pinia et un routeur en mémoire. */
export async function mountView(component, { routes = [], path = '/', props = {} } = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const vuetify = createVuetify({ components, directives })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:p(.*)*', name: 'any', component: { template: '<div />' } }, ...routes,
      ...['login', 'register', 'forgot-password', 'statistics', 'import', 'add-product'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } }))],
  })
  router.push(path)
  await router.isReady()
  const wrapper = mount(component, { props, global: { plugins: [pinia, vuetify, router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router, pinia }
}

export { flushPromises }
