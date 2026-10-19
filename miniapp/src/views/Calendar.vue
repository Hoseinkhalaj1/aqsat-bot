<template>
  <div class="page">
    <header class="head">
      <h1>تقویم</h1>
      <div class="row" style="gap: 4px">
        <button class="ibtn" aria-label="ماه قبل" @click="move(-1)"><Icon name="chevR" :size="18" /></button>
        <b style="min-width: 84px; text-align: center; font-size: 14px">{{ MONTHS[jm] }} {{ fa(jy) }}</b>
        <button class="ibtn" aria-label="ماه بعد" @click="move(1)"><Icon name="chevL" :size="18" /></button>
      </div>
    </header>
    <div class="row lbl" style="gap: 12px; flex-wrap: wrap">
      <span><span class="dot" style="background: var(--mint)"></span>دریافتی</span>
      <span><span class="dot" style="background: var(--amber)"></span>در انتظار</span>
      <span><span class="dot" style="background: var(--blue)"></span>پرداخت شد</span>
      <span><span class="dot" style="background: var(--red)"></span>معوقه</span>
    </div>
    <div class="card tight col" style="gap: 8px">
      <div class="grid lbl" style="text-align: center"><div v-for="(w, i) in WD" :key="w" :class="{ 'c-red': i === 6 }">{{ w }}</div></div>
      <div class="grid">
        <div v-for="i in blanks" :key="'b' + i"></div>
        <button v-for="d in days" :key="d.day" class="cell" :class="{ sel: d.day === sel, today: d.g === today, fri: d.fri }"
          :aria-label="fa(d.day) + ' ' + MONTHS[jm] + (d.ev.length ? '، ' + fa(d.ev.length) + ' رویداد' : '')" :aria-pressed="d.day === sel" @click="sel = d.day; select()">
          <span>{{ fa(d.day) }}</span>
          <span class="dots"><span v-for="(e, i) in d.ev.slice(0, 4)" :key="i" :style="{ background: e.c }"></span></span>
        </button>
      </div>
    </div>

    <section class="card col" style="gap: 10px" aria-live="polite">
      <div class="between"><h2>{{ fa(sel) }} {{ MONTHS[jm] }} — {{ weekday(selG) }}<span v-if="selG === today" class="c-mint"> (امروز)</span></h2><span class="lbl">{{ selEv.length ? fa(selEv.length) + ' رویداد' : '' }}</span></div>
      <div v-for="(e, i) in selEv" :key="i" class="ev" @click="openEv(e)">
        <span class="evbar" :style="{ background: e.c }"></span>
        <div class="col grow" style="gap: 2px"><b class="ell" style="font-size: 14px">{{ e.t }}</b><span class="lbl">{{ e.s }}</span></div>
        <b style="font-size: 14px">{{ money(e.a) }}</b>
      </div>
      <div v-if="!selEv.length" class="lbl" style="padding: 6px 0">این روز رویدادی ندارد.</div>
    </section>

    <div v-if="c" class="g3">
      <div class="card tight col"><span class="lbl">دریافتی</span><b class="c-mint">{{ fa(c.incomes.length) }} مورد</b></div>
      <div class="card tight col"><span class="lbl">پرداخت، {{ mil(c.total) }}</span><b>{{ fa(c.dues.length) }} مورد</b></div>
      <div class="card tight col"><span class="lbl">بدون منبع</span><b :class="noSrc ? 'c-amber' : ''">{{ fa(noSrc) }} مورد</b></div>
    </div>
    <PaySheet :due="payDue" @close="payDue = null" />
    <DepositSheet :prompt="dep" @close="dep = null" />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import PaySheet from '../components/PaySheet.vue'
import DepositSheet from '../components/DepositSheet.vue'
import { api } from '../api'
import { store, toast, errMsg } from '../store'
import { fa, money, mil, weekday } from '../fmt'
import { MONTHS, fromG, toG, monthLen, satIndex, shift } from '../jalali'
import { select } from '../tg'

const router = useRouter()
const WD = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج']
const today = computed(() => store.boot.today)
const [ty, tm, td] = fromG(store.boot.today)
const jy = ref(ty), jm = ref(tm), sel = ref(td)
const c = ref(null), payDue = ref(null), dep = ref(null)
async function load() { try { c.value = await api('calendar', { jy: jy.value, jm: jm.value }) } catch (e) { toast(errMsg(e), 'err') } }
onMounted(load)
watch([jy, jm, () => store.rev], load)
function move(n) { [jy.value, jm.value] = shift(jy.value, jm.value, n); sel.value = jy.value === ty && jm.value === tm ? td : 1 }

const blanks = computed(() => satIndex(jy.value, jm.value, 1))
const events = computed(() => {
  const by = {}
  if (!c.value) return by
  const add = (g, e) => { const d = fromG(g)[2]; (by[d] = by[d] || []).push(e) }
  for (const i of c.value.incomes) add(i.date, { kind: 'in', t: 'واریز ' + i.title, a: i.amount, c: 'var(--mint)', s: i.deposited ? 'واریز شد' : i.date <= today.value ? 'منتظر تأیید واریز' : 'دریافتی', inc: i })
  for (const d of c.value.dues) {
    const col = d.status === 'paid' ? 'var(--blue)' : d.overdue ? 'var(--red)' : 'var(--amber)'
    const s = d.status === 'paid' ? 'پرداخت شد' : (d.overdue ? 'معوقه' : 'در انتظار') + (d.source ? '، از ' + d.source.title : '، بدون منبع')
    add(d.date, { kind: 'due', t: d.title + (d.shared ? ' (سهم ' + fa(d.share) + '٪)' : ''), a: d.status === 'paid' ? d.mine : d.mine_left, c: col, s, due: d })
  }
  return by
})
const days = computed(() => Array.from({ length: monthLen(jy.value, jm.value) }, (_, i) => {
  const day = i + 1
  return { day, g: toG(jy.value, jm.value, day), ev: events.value[day] || [], fri: (blanks.value + i) % 7 === 6 }
}))
const selG = computed(() => toG(jy.value, jm.value, sel.value))
const selEv = computed(() => events.value[sel.value] || [])
const noSrc = computed(() => (c.value?.dues || []).filter((d) => !d.source && d.status !== 'paid').length)
function openEv(e) {
  if (e.kind === 'due') { if (e.due.status !== 'paid') payDue.value = e.due; else router.push('/ob/' + e.due.ob) }
  else if (!e.inc.deposited) dep.value = { income_id: e.inc.income_id, title: e.inc.title, ym: jy.value + '-' + String(jm.value).padStart(2, '0'), date: e.inc.date, amount: e.inc.amount }
}
</script>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
.cell { height: 52px; border-radius: 12px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; font-size: 14px; font-weight: 700; cursor: pointer; background: var(--card2); border: 1.5px solid transparent; padding: 0; }
.cell.fri { color: var(--red); }
.cell.today { border-color: var(--mint); }
.cell.sel { background: var(--mint-soft); border-color: var(--mint); color: var(--text); }
.dots { display: flex; gap: 3px; height: 5px; }
.dots span { width: 5px; height: 5px; border-radius: 50%; }
.ev { display: flex; align-items: center; gap: 10px; background: var(--card2); border-radius: 14px; padding: 10px 12px; min-height: 52px; cursor: pointer; }
.evbar { width: 4px; align-self: stretch; border-radius: 4px; }
</style>
