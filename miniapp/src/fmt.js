import { fromG, MONTHS, WEEKDAYS } from './jalali'
const FA = '۰۱۲۳۴۵۶۷۸۹'
export const fa = (s) => String(s ?? '').replace(/\d/g, (d) => FA[d])
export const toEn = (s) => String(s ?? '').replace(/[۰-۹]/g, (d) => FA.indexOf(d)).replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
export const money = (n) => fa(Math.round(Number(n) || 0).toLocaleString('en-US')).replace(/,/g, '٬')
export const toman = (n) => money(n) + ' تومان'
export function mil(n) {
  n = Math.round(Number(n) || 0)
  const neg = n < 0 ? '-' : ''; n = Math.abs(n)
  if (n >= 1e9) return neg + fa(+(n / 1e9).toFixed(n % 1e9 ? 1 : 0)).replace('.', '٫') + ' میلیارد'
  if (n >= 1e6) return neg + fa(+(n / 1e6).toFixed(1)).replace('.', '٫') + ' م'
  if (n >= 1e3) return neg + fa(Math.round(n / 1e3)) + ' هزار'
  return neg + fa(n)
}
export function jd(g) { const [, m, d] = fromG(g); return fa(d) + ' ' + MONTHS[m] }
export function jfull(g) { const [y, m, d] = fromG(g); return fa(d) + ' ' + MONTHS[m] + ' ' + fa(y) }
export function jnum(g) { const [y, m, d] = fromG(g); return fa(`${y}/${String(m).padStart(2, '0')}/${String(d).padStart(2, '0')}`) }
export function weekday(g) { return WEEKDAYS[new Date(g + 'T12:00:00').getDay()] }
export function daysFrom(today, g) { return Math.round((new Date(g + 'T12:00:00') - new Date(today + 'T12:00:00')) / 864e5) }
export function rel(today, g) {
  const d = daysFrom(today, g)
  if (d === 0) return 'امروز'
  if (d === 1) return 'فردا'
  if (d === -1) return 'دیروز'
  return d > 0 ? fa(d) + ' روز دیگر' : fa(-d) + ' روز گذشته'
}
export function ymName(ym, withYear = false) { const [y, m] = ym.split('-').map(Number); return MONTHS[m] + (withYear ? ' ' + fa(y) : '') }
export function period(unit, n) {
  n = n || 1
  if (unit === 'week') return n === 1 ? 'هفتگی' : 'هر ' + fa(n) + ' هفته'
  if (unit === 'year') return n === 1 ? 'سالانه' : 'هر ' + fa(n) + ' سال'
  return n === 1 ? 'ماهانه' : 'هر ' + fa(n) + ' ماه'
}
export const TYPE = { loan: 'وام / قسط', recurring: 'دوره‌ای', once: 'یک‌باره' }
export const pct = (a, b) => (b > 0 ? Math.max(0, Math.min(1, a / b)) : 0)
export const ERR = {
  bad_amount: 'مبلغ نامعتبر است', bad_date: 'تاریخ نامعتبر است', bad_title: 'عنوان را بنویسید', bad_input: 'ورودی نامعتبر است',
  not_owner: 'فقط سازنده این مورد می‌تواند تغییرش دهد', not_found: 'پیدا نشد', forbidden: 'دسترسی ندارید', bad_auth: 'احراز هویت ناموفق',
  expired: 'نشست منقضی شده؛ اپ را دوباره باز کنید', no_auth: 'این اپ فقط داخل تلگرام باز می‌شود', network: 'خطای اتصال', server: 'خطای سرور',
}
