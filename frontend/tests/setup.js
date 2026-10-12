// Environnement de test des composants (Vitest + jsdom).
import { config, enableAutoUnmount } from '@vue/test-utils'

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

// Menus et fenêtres Vuetify : positionnement selon visualViewport, absent de jsdom.
globalThis.visualViewport ??= Object.assign(new EventTarget(), { width: 1280, height: 800, offsetLeft: 0, offsetTop: 0, scale: 1 })

// Démonte chaque composant après son test : les fenêtres et menus téléportés
// dans document.body ne débordent pas sur le test suivant.
enableAutoUnmount(afterEach)
