// Environnement de test des composants (Vitest + jsdom).
import { config } from '@vue/test-utils'

// Vuetify utilise ResizeObserver et matchMedia, absents de jsdom.
globalThis.ResizeObserver ??= class {
  observe() {}
  unobserve() {}
  disconnect() {}
}
window.matchMedia ??= () => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} })
window.scrollTo = () => {}
globalThis.CSS ??= { supports: () => false }

config.global.stubs = { transition: false, 'transition-group': false }
