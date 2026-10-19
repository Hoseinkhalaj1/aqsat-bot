import { createApp } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'
import './style.css'
import App from './App.vue'
import { tg, initTelegram, setBack } from './tg'
import Home from './views/Home.vue'

// Telegram opens the app with its launch params in the hash (#tgWebAppData=…); the SDK has already read them
if (!location.hash.startsWith('#/')) history.replaceState(null, '', location.pathname + location.search + '#/')

const routes = [
  { path: '/', component: Home, meta: { root: true } },
  { path: '/obs', component: () => import('./views/Installments.vue'), meta: { root: true } },
  { path: '/income', component: () => import('./views/Income.vue'), meta: { root: true } },
  { path: '/reports', component: () => import('./views/Reports.vue'), meta: { root: true } },
  { path: '/calendar', component: () => import('./views/Calendar.vue'), meta: { root: true } },
  { path: '/ob/:id', component: () => import('./views/ObDetail.vue') },
  { path: '/add', component: () => import('./views/Add.vue') },
  { path: '/edit/:id', component: () => import('./views/Add.vue') },
  { path: '/settings', component: () => import('./views/Settings.vue') },
  { path: '/:rest(.*)*', redirect: '/' },
]
const router = createRouter({ history: createWebHashHistory(), routes, scrollBehavior: () => ({ top: 0 }) })
router.afterEach((to) => setBack(to.meta.root ? null : () => (history.length > 1 ? router.back() : router.push('/'))))

const scheme = tg?.colorScheme === 'light' ? 'light' : 'dark'
document.documentElement.dataset.theme = scheme
initTelegram(scheme)
tg?.onEvent?.('themeChanged', () => { const s = tg.colorScheme === 'light' ? 'light' : 'dark'; document.documentElement.dataset.theme = s; initTelegram(s) })

const app = createApp(App)
app.config.errorHandler = (e) => { console.error(e); window.__bootErr?.(e?.message || e) }
app.use(router).mount('#app')
