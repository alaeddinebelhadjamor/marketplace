import 'vuetify/styles'
import { createVuetify } from 'vuetify'
import { fr } from 'vuetify/locale'

// Charte Mytek : rouge #c70a0a, bleu nuit pour les éléments secondaires.
export default createVuetify({
  locale: { locale: 'fr', messages: { fr } },
  theme: {
    defaultTheme: 'mytek',
    themes: {
      mytek: {
        dark: false,
        colors: {
          primary: '#c70a0a',
          secondary: '#1f2a44',
          accent: '#0066c7',
          success: '#15803d',
          warning: '#b45309',
          error: '#b91c1c',
          info: '#0369a1',
          background: '#f5f6f8',
          surface: '#ffffff',
        },
      },
    },
  },
  defaults: {
    VBtn: { rounded: 'lg' },
    VCard: { rounded: 'lg' },
    VTextField: { variant: 'outlined', density: 'comfortable' },
    VTextarea: { variant: 'outlined', density: 'comfortable' },
    VSelect: { variant: 'outlined', density: 'comfortable' },
    VNumberInput: { variant: 'outlined', density: 'comfortable' },
  },
})
