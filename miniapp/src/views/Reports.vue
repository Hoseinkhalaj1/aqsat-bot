<template>
  <div class="page">
    <header class="head">
      <h1>گزارش‌ها</h1>
      <button class="btn sm" style="background: var(--mint-soft); color: var(--mint-text)" @click="xOpen = true"><Icon name="download" :size="18" />اکسل</button>
    </header>
    <div class="seg" role="group" aria-label="بازه">
      <button v-for="t in TABS" :key="t.k" :class="{ on: tab === t.k }" :aria-pressed="tab === t.k" @click="tab = t.k">{{ t.t }}</button>
    </div>
    <div class="between">
      <button class="ibtn" aria-label="قبلی" @click="off -= tab === 'year' ? 12 : 1"><Icon name="chevR" :size="18" /></button>
      <b>{{ r ? (tab === 'year' ? 'سال ' + fa(r.jy) : tab === 'future' ? 'از ' + MONTHS[r.jm] + ' ' + fa(r.jy) : MONTHS[r.jm] + ' ' + fa(r.jy)) : '' }}</b>
      <button class="ibtn" aria-label="بعدی" @click="off += tab === 'year' ? 12 : 1"><Icon name="chevL" :size="18" /></button>
    </div>

    <template v-if="r">
      <!-- ============ MONTH ============ -->
      <template v-if="tab === 'month'">
        <div class="g2">
          <div class="card col" style="gap: 6px"><span class="lbl">پرداخت‌شده از سررسیدها</span><div class="big">{{ mil(k.paid) }}</div>
            <div class="prog"><div :style="{ width: pct(k.paid, k.due) * 100 + '%', background: 'var(--blue)' }"></div></div><span class="lbl">از {{ mil(k.due) }} سررسید ماه</span></div>
          <div class="card col" style="gap: 6px"><span class="lbl">درآمدِ متعهد</span><div class="big" :class="commitPct > 0.85 ? 'c-red' : commitPct > 0.6 ? 'c-amber' : 'c-mint'">{{ fa(Math.round(commitPct * 100)) }}٪</div>
            <div class="prog"><div :style="{ width: Math.min(100, commitPct * 100) + '%', background: 'var(--amber)' }"></div></div><span class="lbl">{{ mil(k.committed) }} از {{ mil(k.income) }}</span></div>
          <div class="card col" style="gap: 6px"><span class="lbl">مانده بدهی وام‌ها</span><div class="big">{{ mil(k.debt) }}</div><span class="lbl">{{ fa(k.loans) }} وام، {{ fa(k.left_cnt) }} قسط مانده</span></div>
          <div class="card col" style="gap: 6px"><span class="lbl">پرداخت به‌موقع (۶ ماه)</span><div class="big" :class="k.on_time === k.count ? 'c-mint' : 'c-amber'">{{ fa(k.on_time) }} از {{ fa(k.count) }}</div><span class="lbl">کل پرداختی واقعی این ماه: {{ mil(k.cash) }}</span></div>
        </div>

        <section class="card col" style="gap: 14px">
          <h2>تعهدهای {{ MONTHS[r.jm] }} به تفکیک دسته</h2>
          <div v-if="!r.cats.length" class="empty">پرداختی در این ماه نیست.</div>
          <div v-else class="row" style="gap: 16px; align-items: center">
            <div style="position: relative; width: 132px; height: 132px; flex-shrink: 0">
              <svg role="img" aria-label="نمودار دسته‌ها" width="132" height="132" viewBox="0 0 132 132">
                <g transform="rotate(-90 66 66)" fill="none" stroke-width="18">
                  <circle cx="66" cy="66" r="50" stroke="var(--track)" />
                  <circle v-for="(s, i) in donut" :key="i" cx="66" cy="66" r="50" :stroke="s.c" :stroke-dasharray="`${s.len} ${314.16 - s.len}`" :stroke-dashoffset="-s.off" />
                </g>
              </svg>
              <div style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center"><b style="font-size: 18px">{{ mil(catTotal) }}</b><span class="lbl" style="font-size: 10px">کل ماه</span></div>
            </div>
            <div class="col grow" style="gap: 8px; font-size: 13px">
              <div v-for="(c, i) in r.cats" :key="c.id" class="between"><span class="ell"><span class="sq" :style="{ background: PAL[i % PAL.length] }"></span>{{ c.name }}</span><b>{{ fa(Math.round((c.amount / catTotal) * 100)) }}٪</b></div>
            </div>
          </div>
          <div v-for="c in r.cats.filter((c) => c.budget > 0)" :key="'b' + c.id" class="col" style="gap: 4px">
            <div class="between lbl"><span>بودجه {{ c.emoji }} {{ c.name }}</span><span :class="{ 'c-red': c.spent > c.budget }">{{ mil(c.spent) }} از {{ mil(c.budget) }}</span></div>
            <div class="prog"><div :style="{ width: Math.min(100, (c.spent / c.budget) * 100) + '%', background: c.spent > c.budget ? 'var(--red)' : 'var(--mint)' }"></div></div>
          </div>
        </section>

        <section v-if="r.split.length" class="card col" style="gap: 14px">
          <h2>هر دریافتی کجا می‌رود؟</h2>
          <div v-for="s in r.split" :key="s.title" class="col" style="gap: 6px">
            <div class="between" style="font-size: 13px"><b>{{ s.title }}، {{ mil(s.amount) }}</b><span :class="s.free < 0 ? 'c-red' : 'c-mint'">{{ s.free < 0 ? 'کسری ' : '' }}{{ mil(Math.abs(s.free)) }}{{ s.free < 0 ? '' : ' آزاد' }}</span></div>
            <div class="sbar">
              <div v-for="(pt, i) in s.parts" :key="i" :style="{ width: (pt.amount / Math.max(s.amount, 1)) * 100 + '%', background: PAL[i % PAL.length] }"><span v-if="pt.amount / s.amount > 0.14">{{ pt.title }}</span></div>
              <div v-if="s.free > 0" :style="{ width: (s.free / s.amount) * 100 + '%', background: 'var(--mint)' }"><span v-if="s.free / s.amount > 0.14">آزاد</span></div>
            </div>
          </div>
        </section>

        <section v-if="r.top.length" class="card col" style="gap: 12px">
          <h2>بزرگ‌ترین تعهدهای ماهانه</h2>
          <div v-for="t in r.top" :key="t.id" class="col" style="gap: 6px">
            <div class="between" style="font-size: 13px"><span class="ell">{{ t.title }} <span class="lbl">· {{ t.type === 'loan' ? 'تا ' + jd(t.until) : period(t.period_unit, t.period_n) }}<template v-if="t.source">، از {{ t.source }}</template></span></span><b>{{ mil(t.monthly) }}</b></div>
            <div class="prog" style="height: 8px"><div :style="{ width: (t.monthly / r.top[0].monthly) * 100 + '%', background: t.type === 'loan' ? 'var(--violet)' : 'var(--blue)' }"></div></div>
          </div>
        </section>
      </template>

      <!-- ============ YEAR ============ -->
      <template v-if="tab === 'year'">
        <div class="g3">
          <div class="card tight col"><span class="lbl">جمع سال</span><b>{{ mil(yDue) }}</b></div>
          <div class="card tight col"><span class="lbl">پرداخت‌شده</span><b class="c-blue">{{ mil(yPaid) }}</b></div>
          <div class="card tight col"><span class="lbl">میانگین ماه</span><b>{{ mil(yDue / 12) }}</b></div>
        </div>
        <section class="card col" style="gap: 12px">
          <h2>سررسید و پرداخت هر ماه</h2>
          <div class="row lbl" style="gap: 14px"><span><span class="sq" style="background: var(--line2)"></span>سررسید</span><span><span class="sq" style="background: var(--blue)"></span>پرداخت‌شده</span></div>
          <div v-for="m in r.year" :key="m.jm" class="row" style="gap: 8px">
            <span class="lbl" style="width: 52px; flex-shrink: 0" :class="{ 'c-mint': m.jm === cur[1] && r.jy === cur[0] }">{{ MONTHS[m.jm] }}</span>
            <div class="grow" style="position: relative; height: 18px; background: var(--card2); border-radius: 6px; overflow: hidden">
              <div :style="{ position: 'absolute', insetInlineStart: 0, top: 0, bottom: 0, width: (m.due / yMax) * 100 + '%', background: 'var(--line2)', borderRadius: '6px' }"></div>
              <div :style="{ position: 'absolute', insetInlineStart: 0, top: 0, bottom: 0, width: (m.paid / yMax) * 100 + '%', background: 'var(--blue)', borderRadius: '6px' }"></div>
            </div>
            <b style="width: 54px; text-align: left; font-size: 12px">{{ m.due ? mil(m.due) : '—' }}</b>
          </div>
        </section>
      </template>

      <!-- ============ FUTURE ============ -->
      <template v-if="tab === 'future'">
        <section class="card col" style="gap: 14px">
          <h2>دریافتی، تعهد و آزادِ ۶ ماه</h2>
          <div class="row lbl" style="gap: 14px"><span><span class="sq" style="background: var(--line2)"></span>دریافتی</span><span><span class="sq" style="background: var(--blue)"></span>تعهد</span><span><span class="sq" style="background: var(--mint)"></span>آزاد (عدد بالا)</span></div>
          <div class="row" style="gap: 4px; align-items: flex-end">
            <div v-for="f in r.forecast" :key="f.jm" class="col grow" style="align-items: center; gap: 6px">
              <b style="font-size: 12px" :class="f.free < 0 ? 'c-red' : f === worst ? 'c-amber' : 'c-mint'">{{ mil(f.free).replace(' م', '') }}</b>
              <div class="row" style="gap: 3px; align-items: flex-end; height: 132px">
                <div class="vb" :style="{ height: (f.income / fMax) * 130 + 'px', background: 'var(--line2)' }"></div>
                <div class="vb" :style="{ height: (f.due / fMax) * 130 + 'px', background: f.due > f.income ? 'var(--red)' : 'var(--blue)' }"></div>
              </div>
              <span class="lbl" style="font-size: 11px" :class="{ 'c-amber': f === worst }">{{ MONTHS[f.jm] }}</span>
            </div>
          </div>
          <div v-if="worst" class="sub" style="font-size: 13px; background: var(--amber-bg); color: var(--amber-text)">
            پرفشارترین ماه: <b>{{ MONTHS[worst.jm] }}</b> با {{ worst.free < 0 ? 'کسری ' + mil(-worst.free) : mil(worst.free) + ' آزاد' }}.
            <template v-if="freeUp"> {{ freeUp }}</template>
          </div>
        </section>

        <section class="card col" style="gap: 14px">
          <h2>مسیر آزادی از بدهی</h2>
          <div v-if="!r.loans.length" class="empty">وام فعالی ندارید 🎉</div>
          <div v-for="l in r.loans" :key="l.id" class="row" style="gap: 12px; align-items: stretch">
            <div class="col" style="align-items: center; gap: 0"><div class="tdot"></div><div style="width: 2px; flex: 1; background: var(--line2)"></div></div>
            <div class="col" style="gap: 2px; padding-bottom: 14px">
              <b style="font-size: 14px">{{ l.title }} تمام می‌شود</b>
              <span class="lbl">{{ jfull(l.end) }}، {{ fa(l.left_cnt) }} قسط، {{ mil(l.left) }}{{ l.share < 100 ? ' سهم شما' : '' }} مانده</span>
              <span class="c-mint" style="font-size: 12px">بعد از آن ماهانه {{ mil(l.monthly) }} آزادتر</span>
            </div>
          </div>
          <div v-if="r.loans.length" class="row"><div style="width: 14px; height: 14px; border-radius: 50%; background: var(--mint)"></div><b class="c-mint">بدون وام از {{ afterLast }}</b></div>
        </section>
      </template>

      <button class="btn blue block" style="min-height: 52px" @click="sendSummary"><Icon name="send" :size="18" />ارسال خلاصه {{ MONTHS[r.jm] }} در ربات</button>
    </template>
    <div v-else class="skel" style="height: 400px"></div>

    <Sheet v-model="xOpen" title="خروجی اکسل">
      <div class="col" style="gap: 10px">
        <div class="lbl">فایل در چت ربات برایتان ارسال می‌شود.</div>
        <button class="btn mint block" @click="excel(cur[0])">سال {{ fa(cur[0]) }}</button>
        <button class="btn block" @click="excel(0)">همه سوابق</button>
      </div>
    </Sheet>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import Icon from '../components/Icon.vue'
import Sheet from '../components/Sheet.vue'
import { api } from '../api'
import { store, act, toast, errMsg } from '../store'
import { fa, mil, jd, jfull, period, pct } from '../fmt'
import { MONTHS, fromG, shift } from '../jalali'

const TABS = [{ k: 'month', t: 'این ماه' }, { k: 'year', t: 'سالانه' }, { k: 'future', t: 'آینده' }]
const PAL = ['var(--blue)', 'var(--amber)', 'var(--violet)', 'var(--mint)', 'var(--blue3)', '#6B7280', 'var(--red)', 'var(--blue2)']
const tab = ref('month'), off = ref(0), r = ref(null), xOpen = ref(false)
const cur = computed(() => fromG(store.boot.today))
const k = computed(() => r.value.kpi)
async function load() { try { r.value = await api('reports', { off: off.value }) } catch (e) { toast(errMsg(e), 'err') } }
onMounted(load)
watch([off, () => store.rev], load)
watch(tab, () => { off.value = 0 })

const commitPct = computed(() => (k.value.income ? k.value.committed / k.value.income : 0))
const catTotal = computed(() => r.value.cats.reduce((s, c) => s + c.amount, 0) || 1)
const donut = computed(() => { let o = 0; return r.value.cats.map((c, i) => { const len = (c.amount / catTotal.value) * 314.16; const s = { len: Math.max(0, len - 1.5), off: o, c: PAL[i % PAL.length] }; o += len; return s }) })
const yDue = computed(() => r.value.year.reduce((s, m) => s + m.due, 0))
const yPaid = computed(() => r.value.year.reduce((s, m) => s + m.paid, 0))
const yMax = computed(() => Math.max(1, ...r.value.year.map((m) => m.due)))
const fMax = computed(() => Math.max(1, ...r.value.forecast.flatMap((f) => [f.income, f.due])))
const worst = computed(() => r.value.forecast.reduce((a, b) => (b.free < a.free ? b : a), r.value.forecast[0]))
const freeUp = computed(() => {
  const l = r.value.loans[0]
  if (!l) return ''
  const [y, m] = fromG(l.end); const [ny, nm] = shift(y, m, 1)
  return 'از ' + MONTHS[nm] + (ny !== cur.value[0] ? ' ' + fa(ny) : '') + '، با تمام شدن «' + l.title + '»، ماهانه ' + mil(l.monthly) + ' آزاد می‌شود.'
})
const afterLast = computed(() => { const l = r.value.loans[r.value.loans.length - 1]; const [y, m] = fromG(l.end); const [ny, nm] = shift(y, m, 1); return MONTHS[nm] + ' ' + fa(ny) })
async function excel(y) { xOpen.value = false; try { await act('excel', { year: y }, 'فایل اکسل در چت ربات ارسال شد') } catch (e) {} }
async function sendSummary() { try { await act('send_summary', { off: tab.value === 'month' ? off.value : 0 }, 'خلاصه در ربات ارسال شد') } catch (e) {} }
</script>

<style scoped>
.sbar { display: flex; height: 24px; border-radius: 8px; overflow: hidden; gap: 2px; background: var(--track); }
.sbar > div { text-overflow: ellipsis; padding: 0 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; color: #0B0D11; overflow: hidden; white-space: nowrap; min-width: 0; }
.vb { width: 14px; border-radius: 4px 4px 0 0; transition: height .5s; }
.tdot { width: 14px; height: 14px; border-radius: 50%; border: 3px solid var(--violet); flex-shrink: 0; }
</style>
