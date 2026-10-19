import { reactive } from 'vue'
import { api } from './api'
import { ERR } from './fmt'
import { haptic } from './tg'
export const store = reactive({ boot: null, error: null, toast: null, rev: 0 })
let tt
export function toast(msg, kind = 'ok') {
  store.toast = { msg, kind }
  clearTimeout(tt); tt = setTimeout(() => (store.toast = null), 2600)
}
export async function loadBoot() { store.boot = await api('boot') }
/** run a mutating call, toast result, bump revision so views reload */
export async function act(action, body = {}, okMsg = null) {
  try {
    const d = await api(action, body)
    if (okMsg) { toast(okMsg); haptic('success') }
    store.rev++
    if (['cat_save', 'cat_del', 'income_save', 'income_del', 'mode', 'settings'].includes(action)) loadBoot()
    return d
  } catch (e) {
    toast(ERR[e.message] || 'خطا: ' + e.message, 'err'); haptic('error')
    throw e
  }
}
export function errMsg(e) { return ERR[e?.message] || 'خطا در دریافت اطلاعات' }
