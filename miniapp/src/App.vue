<template>
  <div v-if="store.error" class="page" style="min-height: 80vh; justify-content: center; align-items: center; text-align: center">
    <Icon name="alert" :size="40" class="c-amber" />
    <h2>{{ store.error }}</h2>
    <div class="lbl">اپ را از دکمه منوی ربات باز کنید.</div>
  </div>
  <template v-else-if="store.boot">
    <router-view v-slot="{ Component }">
      <transition name="fade" mode="out-in"><component :is="Component" :key="$route.fullPath" /></transition>
    </router-view>
    <NavBar v-if="$route.meta.root" />
  </template>
  <div v-else class="page">
    <div class="skel" style="height: 48px; width: 60%"></div>
    <div class="skel" style="height: 170px"></div>
    <div class="g2"><div class="skel" style="height: 190px"></div><div class="skel" style="height: 190px"></div></div>
    <div class="skel" style="height: 260px"></div>
  </div>
  <transition name="fade">
    <div v-if="store.toast" class="toast" :class="store.toast.kind" role="status">{{ store.toast.msg }}</div>
  </transition>
</template>

<script setup>
import { onMounted } from 'vue'
import { store, loadBoot, errMsg } from './store'
import { initData } from './api'
import NavBar from './components/NavBar.vue'
import Icon from './components/Icon.vue'

onMounted(async () => {
  if (!initData) { store.error = 'این اپ فقط داخل تلگرام باز می‌شود'; return }
  try { await loadBoot() } catch (e) { store.error = errMsg(e) }
})
</script>

<style>
.toast { position: fixed; left: 16px; right: 16px; top: calc(12px + env(safe-area-inset-top)); z-index: 100; max-width: 520px; margin: 0 auto; background: var(--card); border: 1px solid var(--mint); color: var(--text); padding: 12px 16px; border-radius: 14px; font-size: 14px; font-weight: 700; box-shadow: var(--shadow); }
.toast.err { border-color: var(--red); }
</style>
