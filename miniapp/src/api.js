import { tg } from './tg'
const devInit = new URLSearchParams(location.search).get('dev_init')
export const initData = tg?.initData || devInit || ''
export async function api(action, body = {}) {
  let r
  try {
    r = await fetch('../api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Init-Data': initData }, body: JSON.stringify({ action, ...body }) })
  } catch (e) { throw new Error('network') }
  const j = await r.json().catch(() => ({ ok: false, error: 'server' }))
  if (!j.ok) throw new Error(j.error || 'server')
  return j.data
}
