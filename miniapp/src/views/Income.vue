<template>
  <div class="page">
    <header class="head">
      <h1>درآمد و برنامه</h1>
      <div class="row" style="gap: 4px">
        <button class="ibtn" aria-label="ماه قبل" @click="off--"><Icon name="chevR" :size="18" /></button>
        <b style="min-width: 84px; text-align: center; font-size: 14px">{{ p ? MONTHS[p.jm] + ' ' + fa(p.jy) : '' }}</b>
        <button class="ibtn" aria-label="ماه بعد" @click="off++"><Icon name="chevL" :size="18" /></button>
      </div>
    </header>

    <div class="seg" role="group" aria-label="حالت تخصیص">
      <button :class="{ on: mode === 'auto' }" :aria-pressed="mode === 'auto'" @click="setMode('auto')">تخصیص خودکار</button>
      <button :class="{ on: mode === 'manual' }" :aria-pressed="mode === 'manual'" @click="setMode('manual')">فقط دستی</button>
    </div>
    <div class="lbl" style="margin-top: -8px">{{ mode === 'auto' ? 'هر قسط به آخرین دریافتیِ قبل از سررسیدش که جا دارد وصل می‌شود. تخصیص دستی همیشه اولویت دارد.' : 'فقط اقساطی که خودتان وصل کرده‌اید در برنامه می‌آیند.' }}</div>

    <template v-if="p">
      <div class="g3">
        <div class="card tight col"><span class="lbl">دریافتی</span><b style="font-size: 17px">{{ mil(p.income) }}</b></div>
        <div class="card tight col"><span class="lbl">کنار گذاشته</span><b class="c-blue" style="font-size: 17px">{{ mil(p.used) }}</b></div>
        <div class="card tight col"><span class="lbl">{{ p.free < 0 ? 'کسری' : 'آزاد' }}</span><b :class="p.free < 0 ? 'c-red' : 'c-mint'" style="font-size: 17px">{{ mil(Math.abs(p.free)) }}</b></div>
      </div>

      <div v-if="!p.occs.length" class="card empty">درآمد ثابتی ثبت نشده. با دکمه پایین اضافه کنید.</div>

      <section v-for="o in p.occs" :key="o.income_id" class="card col" style="gap: 14px">
        <div class="between" style="align-items: flex-start">
          <div class="col" style="gap: 2px">
            <b style="font-size: 17px">{{ o.title }}</b>
            <span class="lbl">{{ jd(o.date) }}، {{ money(o.amount) }}<span v-if="o.overridden" class="c-amber"> (مبلغ این ماه)</span></span>
          </div>
          <div class="row" style="gap: 6px">
            <div class="col" style="align-items: flex-end; gap: 4px">
              <span v-if="o.deposited" class="chip mint">واریز شد</span>
              <span v-else class="chip" :class="{ amber: o.date <= today }">{{ o.date <= today ? 'منتظر تأیید واریز' : rel(today, o.date) }}</span>
              <span v-if="o.set_aside" class="chip blue">کنار گذاشته شد</span>
            </div>
            <button class="ibtn" style="width: 40px; height: 40px" :aria-label="'تنظیمات ' + o.title" @click="editInc(o.income_id)"><Icon name="more" :size="18" /></button>
          </div>
        </div>
        <div class="bar" style="height: 14px">
          <div v-for="(s, i) in segs(o)" :key="i" :style="{ width: s.w + '%', background: s.c }"></div>
        </div>
        <div class="col dz" style="gap: 8px" :data-income="o.income_id" :ref="(el) => setList(el)">
          <div v-for="(it, i) in groupItems(o.items)" :key="it.ob + '-' + i" class="it" :data-ob="it.ob" @click="pick(it)">
            <span class="drag" aria-hidden="true"><Icon name="drag" :size="16" /></span>
            <span class="sw" :style="{ background: COLORS[i % COLORS.length] }"></span>
            <div class="col grow" style="gap: 1px">
              <b class="ell" style="font-size: 14px">{{ it.title }}<span v-if="it.n > 1"> × {{ fa(it.n) }}</span><span v-if="it.shared" class="lbl"> (سهم {{ fa(it.share) }}٪)</span></b>
              <span class="lbl">{{ it.dates }}<template v-if="it.paidAll">، پرداخت شد</template></span>
            </div>
            <div class="col" style="align-items: flex-end; gap: 2px">
              <b style="font-size: 14px">{{ money(it.alloc) }}</b>
              <span class="chip" :class="it.manual ? 'violet' : ''" style="padding: 1px 7px">{{ it.manual ? 'دستی' : 'خودکار' }}</span>
            </div>
          </div>
          <div v-if="!o.items.length" class="lbl dropHint">موردی وصل نیست — اقساط را اینجا بکشید</div>
        </div>
        <div class="between" style="border-top: 1px solid var(--line); padding-top: 12px">
          <div class="col"><span class="lbl">کنار بگذار</span><b class="c-blue" style="font-size: 16px">{{ money(o.used) }}</b></div>
          <div class="col" style="align-items: flex-end"><span class="lbl">{{ o.free < 0 ? 'کسری' : 'آزاد برای خرج' }}</span><b :class="o.free < 0 ? 'c-red' : 'c-mint'" style="font-size: 20px">{{ money(Math.abs(o.free)) }}</b></div>
        </div>
        <div class="row">
          <button v-if="!o.deposited" class="btn amber grow" @click="dep = { income_id: o.income_id, title: o.title, ym: o.ym, date: o.date, amount: o.amount }">ثبت واریز</button>
          <button v-if="!o.set_aside && o.items.length" class="btn blue grow" @click="aside(o)">کنار گذاشتم</button>
          <button v-else-if="o.set_aside" class="btn ghost grow sm" @click="aside(o, true)">برگرداندن «کنار گذاشته»</button>
        </div>
      </section>

      <section v-if="p.prev.length" class="card list">
        <div style="padding: 12px 0"><h2>این ماه، از دریافتی‌های ماه قبل</h2></div>
        <div v-for="x in p.prev" :key="x.id" class="lrow" style="cursor: default">
          <div class="stat" :class="x.status === 'paid' ? 'paid' : 'pending'"><Icon v-if="x.status === 'paid'" name="check" :size="16" :stroke="2.4" /></div>
          <div class="grow col" style="gap: 2px"><b style="font-size: 14px">{{ x.title }}</b><span class="lbl">{{ jd(x.date) }}</span></div>
          <div class="col" style="align-items: flex-end; gap: 2px"><b style="font-size: 14px">{{ money(x.alloc) }}</b><span class="chip blue">از {{ x.source.title }} {{ ymName(x.source.ym) }}</span></div>
        </div>
      </section>

      <section class="unas col" style="gap: 10px">
        <div class="between"><b class="c-amber">بدون منبع</b><span class="lbl">{{ p.unassigned.length ? 'بکش روی یک دریافتی یا بزن' : '' }}</span></div>
        <div class="col dz" style="gap: 8px" data-income="0" :ref="(el) => setList(el)">
          <div v-for="(it, i) in groupItems(p.unassigned.map((u) => ({ ...u, alloc: u.mine, manual: false })))" :key="'u' + it.ob + i" class="it" style="background: var(--amber-bg)" :data-ob="it.ob" @click="pick(it)">
            <span class="drag" aria-hidden="true"><Icon name="drag" :size="16" /></span>
            <div class="col grow" style="gap: 1px"><b class="ell" style="font-size: 14px">{{ it.title }}<span v-if="it.n > 1"> × {{ fa(it.n) }}</span></b><span class="lbl">{{ it.dates }}</span></div>
            <b style="font-size: 14px">{{ money(it.alloc) }}</b>
          </div>
          <div v-if="!p.unassigned.length" class="lbl dropHint">همه پرداخت‌های این ماه منبع دارند 👌</div>
        </div>
        <div v-if="p.unassigned.length" class="lbl" style="color: var(--amber-text)">این مبلغ از هیچ دریافتی کنار گذاشته نشده و از «آزاد» کم شده است.</div>
      </section>
    </template>
    <div v-else class="skel" style="height: 300px"></div>

    <button class="btn ghost block" style="min-height: 52px; border-style: dashed" @click="editInc(0)"><Icon name="plus" :size="18" />درآمد ثابت جدید</button>

    <Sheet v-model="incOpen" :title="incForm.id ? 'ویرایش درآمد' : 'درآمد ثابت جدید'">
      <div class="col" style="gap: 14px">
        <div class="field"><label for="it">عنوان</label><input id="it" v-model="incForm.title" class="in" placeholder="مثلاً حقوق، اجاره"></div>
        <div class="field"><label for="ia">مبلغ ماهانه</label><MoneyInput id="ia" v-model="incForm.amount" /></div>
        <div class="field"><label for="id">روز واریز در ماه</label>
          <select id="id" v-model.number="incForm.day" class="in"><option v-for="n in 31" :key="n" :value="n">{{ fa(n) }}ام هر ماه</option></select></div>
        <div v-if="incForm.id" class="between"><span class="b">فعال</span><button class="switch" :class="{ on: incForm.active }" :aria-pressed="incForm.active" aria-label="فعال" @click="incForm.active = !incForm.active"></button></div>
        <div v-if="incForm.id && p" class="sub col" style="gap: 8px">
          <label for="ov" class="b" style="font-size: 13px">مبلغ واقعی {{ MONTHS[p.jm] }} (اگر با مبلغ ثابت فرق دارد)</label>
          <MoneyInput id="ov" v-model="incForm.override" />
          <div class="row"><button class="btn blue sm grow" @click="saveOverride(false)">ذخیره برای این ماه</button><button class="btn ghost sm" @click="saveOverride(true)">برگشت به ثابت</button></div>
        </div>
        <button class="btn mint block" :disabled="!incForm.title || !incForm.amount" @click="saveInc">ذخیره</button>
        <button v-if="incForm.id" class="btn red block" @click="delInc">حذف درآمد</button>
      </div>
    </Sheet>

    <Sheet :model-value="!!sel" :title="sel ? '«' + sel.title + '» از کدام دریافتی؟' : ''" @update:model-value="sel = null">
      <div v-if="sel" class="col" style="gap: 8px">
        <button v-for="i in incomes" :key="i.id" class="pill" :class="{ on: sel.srcId === i.id }" style="text-align: right" @click="assign(sel.ob, i.id)">{{ i.title }}، روز {{ fa(i.day) }}</button>
        <button class="pill" :class="{ on: !sel.srcId }" style="text-align: right" @click="assign(sel.ob, 0)">بدون تخصیص دستی (طبق حالت کلی)</button>
        <router-link :to="'/ob/' + sel.ob" class="btn ghost block" style="margin-top: 6px">جزئیات این مورد</router-link>
      </div>
    </Sheet>
    <DepositSheet :prompt="dep" @close="dep = null" />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import Sortable from 'sortablejs'
import Icon from '../components/Icon.vue'
import Sheet from '../components/Sheet.vue'
import MoneyInput from '../components/MoneyInput.vue'
import DepositSheet from '../components/DepositSheet.vue'
import { api } from '../api'
import { store, act, toast, errMsg } from '../store'
import { fa, money, mil, jd, rel, ymName } from '../fmt'
import { MONTHS } from '../jalali'
import { tap } from '../tg'

const COLORS = ['var(--blue)', 'var(--violet)', 'var(--blue3)', 'var(--blue2)', '#6B7280']
const off = ref(0), p = ref(null), dep = ref(null), sel = ref(null)
const today = computed(() => store.boot.today)
const mode = computed(() => p.value?.mode || store.boot.me.alloc_mode)
const incomes = computed(() => store.boot.incomes.filter((i) => i.active))
const incOpen = ref(false)
const incForm = ref({})

async function load() { try { p.value = await api('plan', { off: off.value }) } catch (e) { toast(errMsg(e), 'err') } }
onMounted(load)
watch([off, () => store.rev], load)

function groupItems(items) {
  const g = new Map()
  for (const it of items) {
    const k = it.ob
    if (!g.has(k)) g.set(k, { ob: it.ob, title: it.title, alloc: 0, n: 0, manual: it.manual, shared: it.shared, share: it.share, ds: [], paid: 0 })
    const x = g.get(k); x.alloc += it.alloc; x.n++; x.ds.push(it.date); if (it.status === 'paid') x.paid++
  }
  return [...g.values()].map((x) => ({ ...x, paidAll: x.paid === x.n, dates: x.n > 1 ? jd(x.ds[0]) + ' تا ' + jd(x.ds[x.ds.length - 1]) : 'سررسید ' + jd(x.ds[0]) }))
}
function segs(o) {
  const tot = Math.max(o.amount, o.used) || 1
  const s = groupItems(o.items).map((it, i) => ({ w: (it.alloc / tot) * 100, c: COLORS[i % COLORS.length] }))
  if (o.free > 0) s.push({ w: (o.free / tot) * 100, c: 'var(--mint)' })
  return s
}

/* drag & drop between incomes (SortableJS) */
const lists = new Set()
let sortables = []
function setList(el) { if (el) lists.add(el) }
function initSortables() {
  sortables.forEach((s) => s.destroy()); sortables = []
  for (const el of lists) {
    if (!el.isConnected) { lists.delete(el); continue }
    sortables.push(Sortable.create(el, {
      group: 'alloc', sort: false, handle: '.drag', draggable: '.it', animation: 160, delay: 120, delayOnTouchOnly: true,
      ghostClass: 'drag-ghost', chosenClass: 'drag-chosen', onChoose: () => tap(),
      onEnd: (e) => {
        if (e.from === e.to) return
        const ob = +e.item.dataset.ob, inc = +e.to.dataset.income
        e.from.insertBefore(e.item, e.from.children[e.oldIndex] || null) // let Vue re-render
        assign(ob, inc)
      },
    }))
  }
}
watch(p, () => nextTick(initSortables))
onBeforeUnmount(() => sortables.forEach((s) => s.destroy()))

function pick(it) {
  const srcId = p.value.obs.find((o) => o.id === it.ob)?.source?.id || 0
  sel.value = { ...it, srcId }
}
async function assign(ob, inc) { sel.value = null; try { await act('assign', { ob_id: ob, income_id: inc }, inc ? 'وصل شد' : 'به حالت کلی برگشت') } catch (e) {} }
async function setMode(m) { if (m !== mode.value) try { await act('mode', { mode: m }, m === 'auto' ? 'تخصیص خودکار' : 'فقط دستی') } catch (e) {} }
async function aside(o, undo = false) { try { await act('set_aside', { income_id: o.income_id, ym: o.ym, undo }, undo ? 'برگردانده شد' : 'کنار گذاشته شد') } catch (e) {} }

function editInc(id) {
  const i = store.boot.incomes.find((x) => x.id === id)
  const occ = p.value?.occs.find((o) => o.income_id === id)
  incForm.value = i ? { ...i, override: occ?.overridden ? occ.amount : 0 } : { id: 0, title: '', amount: 0, day: 25, active: true }
  incOpen.value = true
}
async function saveInc() { try { await act('income_save', incForm.value, 'ذخیره شد'); incOpen.value = false } catch (e) {} }
async function delInc() { try { await act('income_del', { id: incForm.value.id }, 'حذف شد'); incOpen.value = false } catch (e) {} }
async function saveOverride(reset) {
  const ym = p.value.jy + '-' + String(p.value.jm).padStart(2, '0')
  try { await act('income_override', { id: incForm.value.id, ym, amount: reset ? 0 : incForm.value.override }, 'ذخیره شد'); incOpen.value = false } catch (e) {}
}
</script>

<style scoped>
.it { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--card2); border-radius: 14px; min-height: 52px; cursor: pointer; }
.drag { color: var(--faint); cursor: grab; padding: 8px 2px; margin: -8px 0; touch-action: none; display: flex; }
.sw { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.dropHint { border: 1.5px dashed var(--line2); border-radius: 14px; padding: 14px; text-align: center; }
.unas { border: 1.5px dashed var(--amber-line); background: var(--amber-bg); border-radius: 20px; padding: 16px; }
</style>
