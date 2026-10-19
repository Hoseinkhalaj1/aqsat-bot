<template>
  <div class="page">
    <header class="head">
      <div class="col" style="gap: 2px">
        <h1>سلام {{ me.name }}</h1>
        <div class="lbl" style="font-size: 13px">{{ weekday(today) }} {{ jfull(today) }}</div>
      </div>
      <router-link to="/settings" class="ibtn" aria-label="تنظیمات"><Icon name="gear" /></router-link>
    </header>

    <template v-if="d">
      <!-- deposit prompts -->
      <section v-for="p in d.prompts" :key="p.income_id + p.ym" class="prompt" :aria-label="'واریز ' + p.title">
        <div class="row">
          <div class="pico"><Icon name="wallet" /></div>
          <div class="col grow">
            <b>{{ p.date === today ? 'امروز روز واریز «' + p.title + '» است' : '«' + p.title + '» واریز شد؟' }}</b>
            <span style="font-size: 13px; color: var(--amber-text)">{{ toman(p.amount) }}<template v-if="p.date !== today">، موعد {{ rel(today, p.date) }}</template></span>
          </div>
        </div>
        <div class="row">
          <button class="btn amber grow" @click="openDeposit(p, true)">بله، واریز شد</button>
          <button class="btn ghost grow" style="color: var(--amber-text); border-color: var(--amber-line)" @click="openDeposit(p, false)">مبلغ دیگری</button>
        </div>
      </section>

      <router-link v-if="d.overdue.count" to="/obs?f=overdue" class="card row" style="border-color: var(--red); color: var(--text)">
        <div class="stat over"><Icon name="alert" :size="18" /></div>
        <div class="grow"><b>{{ fa(d.overdue.count) }} پرداخت معوقه</b><div class="lbl">{{ toman(d.overdue.sum) }} سهم شما</div></div>
        <Icon name="chevL" :size="18" class="c-muted" />
      </router-link>

      <!-- hero: real free money -->
      <section class="card hero col" style="gap: 12px" aria-label="آزاد این ماه">
        <div class="between">
          <span class="lbl" style="font-size: 13px">آزادِ واقعی {{ MONTHS[cur[1]] }}</span>
          <span class="chip mint">بعد از همه تعهدها</span>
        </div>
        <div class="row" style="align-items: baseline; gap: 8px">
          <div class="heroNum" :class="plan.free < 0 ? 'c-red' : 'c-mint'">{{ plan.free < 0 ? '−' : '' }}{{ money(Math.abs(plan.free)) }}</div>
          <span class="lbl">تومان</span>
        </div>
        <div class="lbl">{{ plan.free < 0 ? 'کسری! ' : '' }}از {{ toman(plan.income) }} دریافتی این ماه</div>
        <div class="bar">
          <div :style="{ width: w(plan.used) + '%', background: 'var(--blue)' }"></div>
          <div :style="{ width: w(plan.unassigned_sum) + '%', background: 'var(--amber)' }"></div>
          <div :style="{ width: w(Math.max(0, plan.free)) + '%', background: 'var(--mint)' }"></div>
        </div>
        <div class="g3">
          <div class="col"><span class="lbl"><span class="dot" style="background: var(--blue)"></span>کنار گذاشته</span><b>{{ mil(plan.used) }}</b></div>
          <router-link to="/income" class="col" style="color: inherit"><span class="lbl"><span class="dot" style="background: var(--amber)"></span>بدون منبع</span><b class="c-amber">{{ mil(plan.unassigned_sum) }}</b></router-link>
          <div class="col"><span class="lbl"><span class="dot" style="background: var(--mint)"></span>آزاد</span><b class="c-mint">{{ mil(Math.max(0, plan.free)) }}</b></div>
        </div>
      </section>

      <!-- per income -->
      <section class="col" style="gap: 10px" aria-label="مانده هر دریافتی">
        <div class="between"><h2>از هر دریافتی چقدر خالی دارم؟</h2><router-link to="/income" class="lbl c-mint" style="min-height: 44px; display: flex; align-items: center">برنامه کامل</router-link></div>
        <div v-if="!plan.occs.length" class="card empty">
          هنوز درآمد ثابتی ثبت نکرده‌اید.<br><router-link to="/income" class="btn mint sm" style="margin-top: 10px">ثبت درآمد</router-link>
        </div>
        <div class="g2">
          <router-link v-for="o in plan.occs" :key="o.income_id" to="/income" class="card col inc" style="gap: 10px; color: var(--text)">
            <div class="between"><b class="ell">{{ o.title }}</b><span class="lbl">{{ jd(o.date) }}</span></div>
            <Ring :value="o.amount ? o.used / o.amount : 1" :color="o.free < 0 ? 'var(--red)' : 'var(--blue)'" style="align-self: center" :label="'آزاد ' + mil(o.free)">
              <b :class="o.free < 0 ? 'c-red' : 'c-mint'" style="font-size: 16px">{{ mil(Math.abs(o.free)).replace(' م', '') }}</b>
              <span style="font-size: 10px; color: var(--muted)">{{ o.free < 0 ? 'کسری' : (Math.abs(o.free) >= 1e6 ? 'میلیون آزاد' : 'آزاد') }}</span>
            </Ring>
            <div class="lbl" style="text-align: center">{{ fa(new Set(o.items.map((i) => i.ob)).size) }} مورد متصل، {{ mil(o.used) }}</div>
            <span class="chip" :class="occChip(o).c" style="align-self: center">{{ occChip(o).t }}</span>
          </router-link>
        </div>
      </section>

      <div class="g2">
        <div class="card col" style="gap: 6px">
          <span class="lbl">بدهی باقیمانده</span>
          <div class="big">{{ mil(d.debt.left) }}</div>
          <div class="prog"><div :style="{ width: (d.debt.total ? (1 - d.debt.left / d.debt.total) * 100 : 0) + '%', background: 'var(--blue)' }"></div></div>
          <span class="lbl">{{ d.debt.free_date ? 'آزادی از بدهی: ' + jfull(d.debt.free_date) : 'وامی ندارید' }}</span>
        </div>
        <div class="card col" style="gap: 6px">
          <span class="lbl">معوقه</span>
          <div class="big" :class="d.overdue.count ? 'c-red' : 'c-mint'">{{ d.overdue.count ? fa(d.overdue.count) + ' مورد' : 'ندارید' }}</div>
          <span class="lbl">سررسید بعدی</span>
          <b v-if="nextDue" style="font-size: 13px">{{ nextDue.title }}، {{ rel(today, nextDue.date) }}</b>
          <span v-else class="lbl">—</span>
        </div>
      </div>

      <!-- months: current (+ next after the 20th) -->
      <section v-for="(m, i) in d.months" :key="m.jm" class="card list" :aria-label="'اقساط ' + MONTHS[m.jm]">
        <div class="between" style="padding: 10px 0">
          <div class="col" style="gap: 2px">
            <h2>اقساط {{ MONTHS[m.jm] }} و دریافتی متصل</h2>
            <span class="lbl">جمع {{ mil(m.total) }}، {{ m.left ? 'مانده پرداخت ' + mil(m.left) : 'همه پرداخت شده' }}</span>
          </div>
          <span v-if="i === 1" class="chip">ماه بعد</span>
        </div>
        <DueRow v-for="g in groupDues(m.dues)" :key="g.id" :g="g" :today="today" @open="openDue" />
        <div v-if="!m.dues.length" class="empty">پرداختی در این ماه ندارید.</div>
        <div v-if="i === 1 && m.dues.length" class="lbl" style="padding: 4px 0 12px">{{ fromSplit(m) }}</div>
      </section>
    </template>
    <template v-else>
      <div class="skel" style="height: 170px"></div>
      <div class="g2"><div class="skel" style="height: 190px"></div><div class="skel" style="height: 190px"></div></div>
    </template>

    <router-link to="/add" class="fab" aria-label="افزودن مورد جدید"><Icon name="plus" :size="26" :stroke="2.4" /></router-link>
    <PaySheet :due="payDue" @close="payDue = null" />
    <DepositSheet :prompt="dep" :quick="depQuick" @close="dep = null" />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import Ring from '../components/Ring.vue'
import DueRow from '../components/DueRow.vue'
import PaySheet from '../components/PaySheet.vue'
import DepositSheet from '../components/DepositSheet.vue'
import { groupDues } from '../components/dues'
import { api } from '../api'
import { store, toast, errMsg } from '../store'
import { fa, money, toman, mil, jd, jfull, rel, weekday, ymName } from '../fmt'
import { MONTHS, fromG } from '../jalali'

const router = useRouter()
const me = computed(() => store.boot.me)
const today = computed(() => store.boot.today)
const cur = computed(() => fromG(today.value))
const d = ref(null)
const plan = computed(() => d.value.plan)
const payDue = ref(null), dep = ref(null), depQuick = ref(false)
const nextDue = computed(() => d.value?.upcoming?.[0])

async function load() { try { d.value = await api('dash') } catch (e) { toast(errMsg(e), 'err') } }
onMounted(load)
watch(() => store.rev, load)

const w = (v) => (plan.value.income > 0 ? Math.max(0, (v / Math.max(plan.value.income, plan.value.used + plan.value.unassigned_sum)) * 100) : 0)
function occChip(o) {
  if (o.deposited && o.set_aside) return { t: 'واریز شد، کنار گذاشته', c: 'mint' }
  if (o.deposited) return { t: 'واریز شد، کنار نگذاشته', c: 'blue' }
  if (o.date <= today.value) return { t: 'منتظر تأیید واریز', c: 'amber' }
  return { t: rel(today.value, o.date), c: '' }
}
function fromSplit(m) {
  const by = {}
  for (const x of m.dues) { const k = x.source ? ymName(x.source.ym) : 'بدون منبع'; by[k] = (by[k] || 0) + x.mine }
  return Object.entries(by).map(([k, v]) => (k === 'بدون منبع' ? k : 'از دریافتی‌های ' + k) + ': ' + mil(v)).join('، ')
}
function openDue(g) {
  const open = g.items.find((x) => x.status !== 'paid')
  if (open) payDue.value = open; else router.push('/ob/' + g.ob)
}
function openDeposit(p, quick) { depQuick.value = quick; dep.value = { ...p } }
</script>

<style scoped>
.prompt { background: var(--amber-bg); border: 1px solid var(--amber-line); border-radius: 20px; padding: 16px; display: flex; flex-direction: column; gap: 12px; }
.pico { width: 44px; height: 44px; border-radius: 14px; background: var(--amber-soft); color: var(--amber); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.hero { background: var(--hero); border-color: var(--hero-line); }
.heroNum { font-size: 38px; font-weight: 800; line-height: 1.1; letter-spacing: -.5px; }
.inc { transition: transform .15s; }
.inc:active { transform: scale(.98); }
</style>
