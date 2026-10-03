<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, errorMessage } from '@/services/api'
import { downloadFile, openFile } from '@/services/files'
import { useInboxStore } from '@/stores/inbox'
import { dateTime } from '@/utils/format'

const MAX_FILES = 10
const ACCEPT = '.jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx,.xls,.xlsx,.csv,.wav,.mp3'

const inbox = useInboxStore()
const route = useRoute()

const tab = ref('reclamations')
const filter = ref('open')
const selectedId = ref(null)
const messages = ref([])
const loadingMessages = ref(false)
const reply = reactive({ text: '', files: [], sending: false, error: '' })
const create = reactive({ open: false, text: '', files: [], sending: false, error: '' })
const resolveDialog = ref(false)
const snackbar = reactive({ show: false, text: '', color: 'success' })
const thread = ref(null)

const STATUS = {
  0: { label: 'Notification', color: 'info' },
  1: { label: 'Ouverte', color: 'warning' },
  2: { label: 'Résolue', color: 'success' },
}

const reclamations = computed(() => {
  const list = inbox.reclamations
  if (filter.value === 'open') return list.filter((r) => r.type === 1)
  if (filter.value === 'resolved') return list.filter((r) => r.type === 2)
  return list
})
const counts = computed(() => ({
  open: inbox.reclamations.filter((r) => r.type === 1).length,
  resolved: inbox.reclamations.filter((r) => r.type === 2).length,
  awaiting: inbox.reclamations.filter((r) => r.admin_viewed === 0 && r.type === 1).length,
}))
const selected = computed(() => inbox.items.find((r) => r.id === selectedId.value) || null)
const canReply = computed(() => selected.value?.type === 1)

const preview = (r) => {
  const t = r.last_message || ''
  return t ? (t.length > 90 ? `${t.slice(0, 90)}…` : t) : 'Aucun message, ouvrez la conversation'
}

function notify(text, color = 'success') {
  Object.assign(snackbar, { show: true, text, color })
}

async function openThread(id) {
  selectedId.value = id
  loadingMessages.value = true
  reply.error = ''
  try {
    const { data } = await api.get(`/api/reclamations/${id}/messages`)
    messages.value = data
    const item = inbox.items.find((r) => r.id === id)
    if (item && item.vendeur_viewed === 0) await inbox.markSeen(id)
    await nextTick()
    thread.value?.scrollTo({ top: thread.value.scrollHeight, behavior: 'smooth' })
  } catch (e) {
    notify(errorMessage(e), 'error')
  } finally {
    loadingMessages.value = false
  }
}

function addFiles(target, files) {
  const list = Array.isArray(files) ? files : files ? [files] : []
  const names = new Set(target.files.map((f) => f.name))
  target.files = [...target.files, ...list.filter((f) => !names.has(f.name))].slice(0, MAX_FILES)
}

function formData(text, files) {
  const body = new FormData()
  body.append('message', text)
  files.forEach((f) => body.append('attachments[]', f))
  return body
}

async function sendReply() {
  if (!reply.text.trim()) return
  reply.sending = true
  reply.error = ''
  try {
    await api.post(`/api/reclamations/${selectedId.value}/reply`, formData(reply.text, reply.files))
    Object.assign(reply, { text: '', files: [] })
    await Promise.all([openThread(selectedId.value), inbox.refresh()])
  } catch (e) {
    reply.error = errorMessage(e, 'Erreur lors de l\'envoi')
  } finally {
    reply.sending = false
  }
}

async function createReclamation() {
  if (!create.text.trim()) {
    create.error = 'Veuillez saisir un message.'
    return
  }
  create.sending = true
  create.error = ''
  try {
    const { data } = await api.post('/api/reclamations', formData(create.text, create.files))
    Object.assign(create, { open: false, text: '', files: [] })
    await inbox.refresh()
    filter.value = 'open'
    tab.value = 'reclamations'
    notify('Réclamation envoyée avec succès')
    openThread(data.reclamation_id)
  } catch (e) {
    create.error = errorMessage(e, 'Erreur lors de la création')
  } finally {
    create.sending = false
  }
}

async function resolve() {
  try {
    await api.put(`/api/reclamations/${selectedId.value}/resolve`)
    resolveDialog.value = false
    await inbox.refresh()
    notify('Réclamation marquée comme résolue')
  } catch (e) {
    notify(errorMessage(e), 'error')
  }
}

const fileIcon = (type = '') =>
  type.startsWith('image/') ? 'mdi-file-image-outline'
    : type.includes('pdf') ? 'mdi-file-pdf-box'
      : type.includes('sheet') || type.includes('excel') ? 'mdi-file-excel-outline'
        : type.includes('word') ? 'mdi-file-word-outline'
          : type.startsWith('audio/') ? 'mdi-file-music-outline' : 'mdi-paperclip'

// Nouveau message reçu en temps réel sur la conversation ouverte : on la recharge.
watch(() => inbox.lastEvent, (event) => {
  if (event && event.reclamation_id === selectedId.value) openThread(selectedId.value)
})

onMounted(async () => {
  if (!inbox.loaded) await inbox.refresh().catch(() => {})
  const notificationId = Number(route.query.notification)
  if (notificationId) {
    tab.value = 'notifications'
    openThread(notificationId)
  }
})
</script>

<template>
  <div class="d-flex flex-wrap align-center ga-3 mb-4">
    <div class="flex-grow-1">
      <h1 class="page-title">Centre de support</h1>
      <p class="page-subtitle">{{ counts.open }} ouverte(s) · {{ counts.resolved }} résolue(s) · {{ counts.awaiting }} en attente de lecture par le support</p>
    </div>
    <v-btn color="primary" prepend-icon="mdi-plus" data-test="new" @click="create.open = true">Nouvelle réclamation</v-btn>
  </div>

  <v-row>
    <v-col cols="12" md="5" lg="4">
      <v-card>
        <v-tabs v-model="tab" color="primary" grow>
          <v-tab value="reclamations">Réclamations</v-tab>
          <v-tab value="notifications">
            Notifications
            <v-badge v-if="inbox.unreadNotifications.length" :content="inbox.unreadNotifications.length" color="primary" inline />
          </v-tab>
        </v-tabs>
        <v-divider />
        <div v-if="tab === 'reclamations'" class="pa-2">
          <v-chip-group v-model="filter" mandatory selected-class="text-primary">
            <v-chip value="open" size="small" filter>Ouvertes</v-chip>
            <v-chip value="resolved" size="small" filter>Résolues</v-chip>
            <v-chip value="all" size="small" filter>Toutes</v-chip>
          </v-chip-group>
        </div>
        <v-list lines="three" max-height="620" class="overflow-y-auto">
          <v-list-item
            v-for="r in tab === 'reclamations' ? reclamations : inbox.notifications"
            :key="r.id"
            :active="r.id === selectedId"
            color="primary"
            @click="openThread(r.id)"
          >
            <v-list-item-title class="d-flex align-center">
              <span class="font-weight-medium">#{{ r.id }}</span>
              <v-chip :color="STATUS[r.type].color" size="x-small" variant="tonal" class="ml-2">{{ STATUS[r.type].label }}</v-chip>
              <v-icon v-if="r.vendeur_viewed === 0" color="primary" size="10" class="ml-2" aria-label="Non lu">mdi-circle</v-icon>
            </v-list-item-title>
            <v-list-item-subtitle class="text-pre-line">{{ preview(r) }}</v-list-item-subtitle>
            <template #append><span class="text-caption text-medium-emphasis">{{ dateTime(r.updated_at) }}</span></template>
          </v-list-item>
          <v-list-item v-if="!(tab === 'reclamations' ? reclamations : inbox.notifications).length">
            <v-list-item-title class="text-medium-emphasis text-center py-6">
              {{ tab === 'reclamations' ? 'Aucune réclamation.' : 'Aucune notification.' }}
            </v-list-item-title>
          </v-list-item>
        </v-list>
      </v-card>
    </v-col>

    <v-col cols="12" md="7" lg="8">
      <v-card v-if="selected" class="d-flex flex-column" min-height="560">
        <v-card-item>
          <v-card-title>
            {{ selected.type === 0 ? 'Notification' : 'Réclamation' }} #{{ selected.id }}
            <v-chip :color="STATUS[selected.type].color" size="small" variant="tonal" class="ml-2">{{ STATUS[selected.type].label }}</v-chip>
          </v-card-title>
          <v-card-subtitle>Ouverte le {{ dateTime(selected.created_at) }}</v-card-subtitle>
          <template v-if="canReply" #append>
            <v-btn color="success" variant="tonal" prepend-icon="mdi-check-circle-outline" @click="resolveDialog = true">Problème résolu</v-btn>
          </template>
        </v-card-item>
        <v-divider />

        <div ref="thread" class="flex-grow-1 pa-4 overflow-y-auto" style="max-height: 460px">
          <v-progress-linear v-if="loadingMessages" indeterminate color="primary" />
          <div v-for="m in messages" :key="m.id" class="d-flex mb-4" :class="m.sender === 1 ? 'justify-end' : 'justify-start'">
            <v-sheet :color="m.sender === 1 ? 'red-lighten-5' : 'grey-lighten-4'" rounded="lg" class="pa-3" max-width="80%">
              <div class="text-caption font-weight-bold mb-1">{{ m.sender === 1 ? 'Vous' : 'Support Mytek' }}</div>
              <div class="text-body-2 text-pre-line">{{ m.message }}</div>
              <div v-for="a in m.attachments" :key="a.id" class="d-flex align-center mt-2">
                <v-icon size="18" class="mr-1">{{ fileIcon(a.file_type) }}</v-icon>
                <a href="#" class="text-body-2 mr-2" @click.prevent="openFile(`/api/attachments/view/${a.filename}`).catch(() => notify('Fichier introuvable', 'error'))">{{ a.filename }}</a>
                <v-btn icon="mdi-download" size="x-small" variant="text" :aria-label="`Télécharger ${a.filename}`"
                  @click="downloadFile(`/api/attachments/download/${a.filename}`, {}, a.filename).catch(() => notify('Fichier introuvable', 'error'))" />
              </div>
              <div class="text-caption text-medium-emphasis mt-1 text-right">{{ dateTime(m.created_at) }}</div>
            </v-sheet>
          </div>
        </div>

        <v-divider />
        <div v-if="canReply" class="pa-4">
          <v-textarea v-model="reply.text" label="Votre message" rows="2" auto-grow hide-details="auto" :disabled="reply.sending" />
          <div class="d-flex flex-wrap align-center ga-2 mt-2">
            <v-file-input
              :model-value="[]"
              label="Joindre des fichiers"
              :accept="ACCEPT"
              multiple
              density="compact"
              hide-details
              prepend-icon="mdi-paperclip"
              style="max-width: 260px"
              @update:model-value="(f) => addFiles(reply, f)"
            />
            <v-chip v-for="f in reply.files" :key="f.name" size="small" closable @click:close="reply.files = reply.files.filter((x) => x !== f)">{{ f.name }}</v-chip>
            <v-spacer />
            <v-btn color="primary" prepend-icon="mdi-send" :loading="reply.sending" :disabled="!reply.text.trim()" @click="sendReply">Envoyer</v-btn>
          </div>
          <v-alert v-if="reply.error" type="error" variant="tonal" density="compact" class="mt-2">{{ reply.error }}</v-alert>
        </div>
        <v-card-text v-else-if="selected.type === 2" class="text-medium-emphasis">Cette réclamation est résolue : aucune nouvelle réponse n'est possible.</v-card-text>
      </v-card>

      <v-card v-else class="pa-10 text-center text-medium-emphasis">
        <v-icon size="48">mdi-forum-outline</v-icon>
        <p class="mt-3">Sélectionnez une conversation, ou ouvrez une nouvelle réclamation.</p>
      </v-card>
    </v-col>
  </v-row>

  <v-dialog v-model="create.open" max-width="560" persistent>
    <v-card title="Nouvelle réclamation" subtitle="Notre équipe vous répond dans les meilleurs délais.">
      <v-card-text>
        <v-textarea v-model="create.text" label="Expliquez votre problème en détail *" rows="5" auto-grow data-test="create-text" />
        <v-file-input :model-value="[]" label="Pièces jointes (10 au maximum, 10 Mo chacune)" :accept="ACCEPT" multiple prepend-icon="mdi-paperclip" @update:model-value="(f) => addFiles(create, f)" />
        <div class="d-flex flex-wrap ga-2">
          <v-chip v-for="f in create.files" :key="f.name" size="small" closable @click:close="create.files = create.files.filter((x) => x !== f)">{{ f.name }}</v-chip>
        </div>
        <v-alert v-if="create.error" type="error" variant="tonal" density="compact" class="mt-3" data-test="create-error">{{ create.error }}</v-alert>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn :disabled="create.sending" @click="create.open = false">Annuler</v-btn>
        <v-btn color="primary" variant="flat" :loading="create.sending" data-test="create-send" @click="createReclamation">Envoyer</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="resolveDialog" max-width="420">
    <v-card prepend-icon="mdi-check-circle-outline" title="Problème résolu ?">
      <v-card-text>La réclamation sera close et vous ne pourrez plus y répondre.</v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn @click="resolveDialog = false">Non, pas encore</v-btn>
        <v-btn color="success" variant="flat" @click="resolve">Oui, c'est résolu</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3500">{{ snackbar.text }}</v-snackbar>
</template>
