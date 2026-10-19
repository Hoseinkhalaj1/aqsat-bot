import { jd, fa, ymName } from '../fmt'
/** collapse consecutive recurring dues of the same item, source and paid-state into one row */
export function groupDues(dues) {
  const out = []
  for (const d of dues) {
    const sk = d.source ? d.source.income_id + '|' + d.source.ym : '-'
    const st = d.status === 'paid' ? 'p' : 'u'
    const last = out[out.length - 1]
    if (last && d.type === 'recurring' && last.ob === d.ob && last._sk === sk && last._st === st && !d.overdue && !last.overdue) {
      last.items.push(d); last.mine += d.mine; last.mine_left += d.mine_left
      continue
    }
    out.push({ ...d, items: [d], _sk: sk, _st: st })
  }
  return out.map((g) => ({ ...g, count: g.items.length, dateText: g.items.length > 1 ? joinDates(g.items) : jd(g.date) }))
}
function joinDates(items) {
  const [, m] = items[0].date.split('-')
  const days = items.map((x) => jd(x.date).split(' ')[0])
  const month = jd(items[items.length - 1].date).split(' ').slice(1).join(' ')
  const sameMonth = items.every((x) => x.date.slice(0, 7) === items[0].date.slice(0, 7)) && m
  return sameMonth ? days.slice(0, -1).join('، ') + ' و ' + days[days.length - 1] + ' ' + month : items.map((x) => jd(x.date)).join('، ')
}
export function statusOf(d, today) {
  if (d.status === 'paid') return 'paid'
  if (d.overdue) return 'over'
  if (d.status === 'partial') return 'partial'
  if (d.date === today) return 'today'
  if (!d.source) return 'none'
  return 'pending'
}
export function sourceText(s) { return s ? 'از ' + s.title + ' ' + ymName(s.ym) : '' }
export function shareText(d) { return d.shared ? ' (سهم ' + fa(d.share) + '٪)' : '' }
