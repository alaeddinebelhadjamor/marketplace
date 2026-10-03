<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useInboxStore } from '@/stores/inbox'
import { dateTime } from '@/utils/format'

const inbox = useInboxStore()
const router = useRouter()
const open = ref(false)
const toast = ref(false)
const toastText = ref('')

const latest = computed(() =>
  [...inbox.notifications].sort((a, b) => new Date(b.created_at) - new Date(a.created_at)).slice(0, 8),
)
const count = computed(() => inbox.unreadNotifications.length)

// À l'ouverture, toutes les notifications sont marquées comme lues (comme la v1).
watch(open, (isOpen) => {
  if (isOpen && count.value > 0) inbox.markAllNotificationsSeen()
})

// Événement temps réel : petit message en bas de l'écran.
watch(
  () => inbox.lastEvent,
  (event) => {
    if (!event) return
    toastText.value = event.kind === 'reply' ? 'Nouvelle réponse du support' : event.preview
    toast.value = true
  },
)

function openItem(item) {
  open.value = false
  router.push({ name: 'reclamations', query: { notification: item.id } })
}
</script>

<template>
  <v-menu v-model="open" :close-on-content-click="false" location="bottom end" width="360">
    <template #activator="{ props }">
      <v-btn v-bind="props" icon :aria-label="`Notifications, ${count} non lue(s)`">
        <v-badge :model-value="count > 0" :content="count > 99 ? '99+' : count" color="primary">
          <v-icon>mdi-bell-outline</v-icon>
        </v-badge>
      </v-btn>
    </template>
    <v-card>
      <v-card-title class="d-flex align-center text-subtitle-1">
        Notifications
        <v-spacer />
        <v-chip v-if="count" size="small" color="primary">{{ count }} non lue(s)</v-chip>
      </v-card-title>
      <v-divider />
      <v-list v-if="latest.length" lines="three" density="compact" max-height="380" class="overflow-y-auto">
        <v-list-item v-for="item in latest" :key="item.id" @click="openItem(item)">
          <template #prepend>
            <v-icon :color="item.vendeur_viewed ? 'grey' : 'primary'" size="10">mdi-circle</v-icon>
          </template>
          <v-list-item-title class="text-body-2 text-wrap">{{ item.last_message || 'Nouvelle notification' }}</v-list-item-title>
          <v-list-item-subtitle>{{ dateTime(item.created_at) }}</v-list-item-subtitle>
        </v-list-item>
      </v-list>
      <v-card-text v-else class="text-center text-medium-emphasis py-8">
        <v-icon size="36" class="mb-2">mdi-bell-sleep-outline</v-icon>
        <div>Aucune notification</div>
      </v-card-text>
    </v-card>
  </v-menu>

  <v-snackbar v-model="toast" color="secondary" location="bottom right" timeout="5000">
    <v-icon start>mdi-bell-ring-outline</v-icon>{{ toastText }}
  </v-snackbar>
</template>
