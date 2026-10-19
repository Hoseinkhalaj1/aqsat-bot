export const tg = window.Telegram?.WebApp
export const inTelegram = !!tg?.initData
export function haptic(type = 'success') { try { tg?.HapticFeedback?.notificationOccurred(type) } catch (e) {} }
export function tap() { try { tg?.HapticFeedback?.impactOccurred('light') } catch (e) {} }
export function select() { try { tg?.HapticFeedback?.selectionChanged() } catch (e) {} }
export function ask(msg) {
  return new Promise((res) => {
    if (inTelegram && tg.showConfirm) { try { return tg.showConfirm(msg, (ok) => res(!!ok)) } catch (e) {} }
    res(window.confirm(msg))
  })
}
let backCb = null
export function setBack(cb) {
  if (!inTelegram) return
  if (backCb) tg.BackButton.offClick(backCb)
  backCb = cb
  if (cb) { tg.BackButton.onClick(cb); tg.BackButton.show() } else tg.BackButton.hide()
}
let mainCb = null
export function setMain(text, cb, opts = {}) {
  if (!inTelegram) return false
  const mb = tg.MainButton
  if (mainCb) mb.offClick(mainCb)
  mainCb = cb
  if (!cb) { mb.hide(); return true }
  mb.setParams({ text, color: opts.color, text_color: opts.textColor, is_active: opts.active !== false })
  if (opts.loading) mb.showProgress(); else mb.hideProgress()
  mb.onClick(cb); mb.show()
  return true
}
export function initTelegram(scheme) {
  if (!tg) return
  try { tg.ready(); tg.expand() } catch (e) {}
  try { tg.disableVerticalSwipes?.() } catch (e) {}
  try { tg.enableClosingConfirmation?.() } catch (e) {}
  const bg = scheme === 'light' ? '#F4F6F9' : '#0B0D11'
  try { tg.setHeaderColor(bg); tg.setBackgroundColor(bg); tg.setBottomBarColor?.(scheme === 'light' ? '#FFFFFF' : '#10131A') } catch (e) {}
}
