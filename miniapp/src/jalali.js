export const MONTHS = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند']
export const WEEKDAYS = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه']
const div = (a, b) => Math.trunc(a / b)
export function g2j(gy, gm, gd) {
  const gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334]
  const gy2 = gm > 2 ? gy + 1 : gy
  let days = 355666 + 365 * gy + div(gy2 + 3, 4) - div(gy2 + 99, 100) + div(gy2 + 399, 400) + gd + gdm[gm - 1]
  let jy = -1595 + 33 * div(days, 12053); days %= 12053
  jy += 4 * div(days, 1461); days %= 1461
  if (days > 365) { jy += div(days - 1, 365); days = (days - 1) % 365 }
  const jm = days < 186 ? 1 + div(days, 31) : 7 + div(days - 186, 30)
  const jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30)
  return [jy, jm, jd]
}
export function j2g(jy, jm, jd) {
  jy += 1595
  let days = -355668 + 365 * jy + div(jy, 33) * 8 + div((jy % 33) + 3, 4) + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186)
  let gy = 400 * div(days, 146097); days %= 146097
  if (days > 36524) { days--; gy += 100 * div(days, 36524); days %= 36524; if (days >= 365) days++ }
  gy += 4 * div(days, 1461); days %= 1461
  if (days > 365) { gy += div(days - 1, 365); days = (days - 1) % 365 }
  let gd = days + 1
  const sal = [0, 31, (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0 ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]
  let gm = 0
  for (; gm < 13 && gd > sal[gm]; gm++) gd -= sal[gm]
  return [gy, gm, gd]
}
const pad = (n) => String(n).padStart(2, '0')
export function fromG(g) { const [y, m, d] = g.slice(0, 10).split('-').map(Number); return g2j(y, m, d) }
export function toG(jy, jm, jd) { const [y, m, d] = j2g(jy, jm, jd); return `${y}-${pad(m)}-${pad(d)}` }
export function isLeap(jy) { const [y, m, d] = j2g(jy, 12, 30); const r = g2j(y, m, d); return r[0] === jy && r[1] === 12 && r[2] === 30 }
export function monthLen(jy, jm) { return jm <= 6 ? 31 : jm <= 11 ? 30 : isLeap(jy) ? 30 : 29 }
export function shift(jy, jm, n) { const t = jy * 12 + jm - 1 + n; return [Math.floor(t / 12), (t % 12) + 1] }
/** saturday-based weekday index (0=شنبه) of a jalali date */
export function satIndex(jy, jm, jd) { return (new Date(toG(jy, jm, jd) + 'T12:00:00').getDay() + 1) % 7 }
