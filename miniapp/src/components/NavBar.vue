<template>
  <nav class="nav" aria-label="منوی اصلی">
    <router-link v-for="i in items" :key="i.to" :to="i.to" class="nv" :class="{ on: $route.path === i.to }" @click="select()">
      <Icon :name="i.icon" /><span>{{ i.t }}</span>
    </router-link>
  </nav>
</template>
<script setup>
import Icon from './Icon.vue'
import { select } from '../tg'
const items = [
  { to: '/', icon: 'home', t: 'خانه' }, { to: '/obs', icon: 'list', t: 'اقساط' }, { to: '/income', icon: 'wallet', t: 'درآمد' },
  { to: '/reports', icon: 'chart', t: 'گزارش‌ها' }, { to: '/calendar', icon: 'cal', t: 'تقویم' },
]
</script>
<style scoped>
.nav { position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; background: var(--nav); border-top: 1px solid var(--line); display: flex; justify-content: space-around; align-items: center; padding: 6px 8px calc(8px + env(safe-area-inset-bottom)); }
.nv { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; font-size: 11px; color: var(--faint); min-width: 58px; min-height: 52px; transition: color .2s; }
.nv.on { color: var(--mint); }
</style>
