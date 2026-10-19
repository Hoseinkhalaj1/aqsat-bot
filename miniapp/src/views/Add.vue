<template>
  <div class="page no-nav">
    <header class="head"><h1>{{ editId ? 'ویرایش' : 'مورد جدید' }}</h1></header>

    <div v-if="!editId" class="seg" role="group" aria-label="نوع">
      <button v-for="t in TYPES" :key="t.k" :class="{ on: f.type === t.k }" :aria-pressed="f.type === t.k" @click="f.type = t.k">{{ t.t }}</button>
    </div>
    <div v-if="!editId" class="lbl" style="margin-top: -8px">{{ TYPES.find((t) => t.k === f.type).h }}</div>

    <div class="field"><label for="t">عنوان</label><input id="t" v-model="f.title" class="in" :placeholder="TYPES.find((t) => t.k === f.type).ex"></div>

    <div class="field">
      <div class="fh">دسته‌بندی</div>
      <div class="chips">
        <button v-for="c in cats" :key="c.id" class="pill" :class="{ on: f.category_id === c.id }" :aria-pressed="f.category_id === c.id" @click="f.category_id = f.category_id === c.id ? 0 : c.id">{{ c.emoji }} {{ c.name }}</button>
        <button class="pill" style="border-style: dashed" @click="catOpen = true">+ دسته جدید</button>
      </div>
    </div>

    <div v-if="f.type === 'loan' || editId" class="field"><label for="cr">طرف حساب</label><input id="cr" v-model="f.creditor" class="in" placeholder="بانک، فروشگاه یا شخص"></div>

    <!-- ========== create: amounts & schedule ========== -->
    <template v-if="!editId">
      <template v-if="f.type === 'loan'">
        <div class="g2">
          <div class="field"><div class="fh">تعداد اقساط</div>
            <div class="stepper"><button aria-label="افزایش" @click="f.count = Math.min(600, f.count + 1)">+</button><b>{{ fa(f.count) }}</b><button aria-label="کاهش" @click="f.count = Math.max(1, f.count - 1)">−</button></div></div>
          <div class="field"><label for="pn">فاصله اقساط</label>
            <select id="pn" v-model.number="f.period_n" class="in"><option :value="1">ماهانه</option><option :value="2">هر ۲ ماه</option><option :value="3">هر ۳ ماه</option><option :value="6">هر ۶ ماه</option><option :value="12">سالانه</option></select></div>
        </div>
        <div class="field">
          <div class="fh">مبلغ</div>
          <div class="seg" role="group" aria-label="نحوه ورود مبلغ">
            <button v-for="m in MODES" :key="m.k" :class="{ on: f.mode === m.k }" :aria-pressed="f.mode === m.k" @click="f.mode = m.k">{{ m.t }}</button>
          </div>
          <MoneyInput v-if="f.mode !== 'manual'" v-model="f.amount" :aria-label="f.mode === 'each' ? 'مبلغ هر قسط' : 'مبلغ کل'" />
          <template v-else>
            <textarea v-model="f.manual" class="in" dir="ltr" style="text-align: right" placeholder="5000000*6&#10;3000000*6"></textarea>
            <div class="lbl">هر مبلغ در یک خط؛ برای تکرار از * استفاده کنید. {{ amounts.length ? fa(amounts.length) + ' قسط خوانده شد' : '' }}<span v-if="amounts.length && amounts.length !== f.count" class="c-amber"> (با تعداد اقساط برابر نیست)</span></div>
          </template>
          <div v-if="amounts.length" class="lbl">جمع کل: {{ toman(total) }}<template v-if="f.mode === 'total'">، هر قسط {{ toman(amounts[0]) }}</template></div>
        </div>
      </template>
      <template v-else>
        <div class="field"><label for="am">{{ f.type === 'recurring' ? 'مبلغ هر دوره' : 'مبلغ' }}</label><MoneyInput id="am" v-model="f.amount" /></div>
        <div v-if="f.type === 'recurring'" class="field"><label for="rp">تکرار</label>
          <select id="rp" v-model="f.rperiod" class="in"><option value="week:1">هفتگی</option><option value="month:1">ماهانه</option><option value="month:2">هر ۲ ماه</option><option value="month:3">هر ۳ ماه</option><option value="month:6">هر ۶ ماه</option><option value="year:1">سالانه</option></select></div>
      </template>

      <div class="g2">
        <div class="field"><div class="fh">{{ f.type === 'loan' ? 'اولین قسط' : f.type === 'recurring' ? 'اولین پرداخت' : 'تاریخ' }}</div><DateField v-model="f.first_date" :today="today" /></div>
        <div v-if="f.type === 'loan' && pastCount > 0" class="field"><div class="fh">پرداخت‌شده تاکنون</div>
          <div class="stepper"><button aria-label="افزایش" @click="f.paid_count = Math.min(pastCount, f.paid_count + 1)">+</button><b>{{ fa(f.paid_count) }}</b><button aria-label="کاهش" @click="f.paid_count = Math.max(0, f.paid_count - 1)">−</button></div></div>
      </div>

      <div v-if="others.length" class="card col" style="gap: 12px; padding: 14px">
        <div class="between">
          <div class="col"><b style="font-size: 14px">مشترک است</b><span class="lbl">هر کس سهم خودش را می‌بیند و یادآوری می‌گیرد</span></div>
          <button class="switch" :class="{ on: f.shared }" :aria-pressed="f.shared" aria-label="مشترک" @click="f.shared = !f.shared"></button>
        </div>
        <template v-if="f.shared">
          <div class="chips"><button v-for="o in others" :key="o.id" class="pill" :class="{ on: f.shared_user_id === o.id }" @click="f.shared_user_id = o.id">با {{ o.name }}</button></div>
          <div class="field"><label for="sh" class="between"><span>سهم شما</span><span class="c-mint">{{ fa(f.owner_share) }}٪</span></label><input id="sh" v-model.number="f.owner_share" type="range" min="1" max="99"></div>
        </template>
      </div>

      <div v-if="incomes.length" class="field">
        <div class="fh">از کدام دریافتی پرداخت شود؟</div>
        <div class="chips">
          <button class="pill" :class="{ on: !f.income_id }" @click="f.income_id = 0">خودکار</button>
          <button v-for="i in incomes" :key="i.id" class="pill" :class="{ on: f.income_id === i.id }" @click="f.income_id = i.id">{{ i.title }} ({{ fa(i.day) }}ام)</button>
        </div>
      </div>

      <div v-if="preview" class="card col" style="gap: 4px; background: var(--hero); border-color: var(--hero-line); font-size: 13px">
        <b class="c-mint">پیش‌نمایش</b><span v-for="(l, i) in preview" :key="i">{{ l }}</span>
      </div>
    </template>

    <!-- ========== edit ========== -->
    <template v-else-if="ob">
      <div class="field"><label for="nt">یادداشت</label><textarea id="nt" v-model="f.note" class="in"></textarea></div>
      <div v-if="ob.type !== 'once'" class="field">
        <label for="ea">{{ ob.type === 'loan' ? 'مبلغ جدید برای همه اقساط پرداخت‌نشده' : 'مبلغ هر دوره' }}</label>
        <MoneyInput id="ea" v-model="f.amount" />
        <div class="lbl">خالی بگذارید تا تغییر نکند. برای تغییر یک قسط خاص، از صفحه جزئیات روی همان قسط بزنید.</div>
      </div>
    </template>

    <button v-if="!inTelegram" class="btn mint block" style="min-height: 52px" :disabled="!valid || busy" @click="submit">{{ editId ? 'ذخیره' : 'ثبت' }}</button>

    <Sheet v-model="catOpen" title="دسته جدید">
      <div class="col" style="gap: 12px">
        <div class="row"><input v-model="newCat.emoji" class="in" style="width: 64px; text-align: center" aria-label="ایموجی"><input v-model="newCat.name" class="in grow" placeholder="نام دسته" aria-label="نام دسته"></div>
        <button class="btn mint block" :disabled="!newCat.name" @click="addCat">افزودن</button>
      </div>
    </Sheet>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Sheet from '../components/Sheet.vue'
import MoneyInput from '../components/MoneyInput.vue'
import DateField from '../components/DateField.vue'
import { api } from '../api'
import { store, act, toast, errMsg } from '../store'
import { inTelegram, setMain } from '../tg'
import { fa, toman, jfull, toEn, mil, period } from '../fmt'
import { fromG, toG, monthLen, shift } from '../jalali'

const TYPES = [
  { k: 'loan', t: 'وام / قسط', h: 'تعداد اقساط مشخص: وام، خرید قسطی، قرض', ex: 'مثلاً وام بانک ملت' },
  { k: 'recurring', t: 'دوره‌ای', h: 'بدون پایان، هر ماه/هفته/سال تکرار می‌شود', ex: 'مثلاً اینترنت، باشگاه' },
  { k: 'once', t: 'یک‌باره', h: 'یک پرداخت با تاریخ مشخص', ex: 'مثلاً بیمه ماشین' },
]
const MODES = [{ k: 'each', t: 'هر قسط' }, { k: 'total', t: 'مبلغ کل' }, { k: 'manual', t: 'متفاوت' }]
const route = useRoute(), router = useRouter()
const editId = computed(() => +route.params.id || 0)
const today = computed(() => store.boot.today)
const cats = computed(() => store.boot.cats)
const others = computed(() => store.boot.others)
const incomes = computed(() => store.boot.incomes.filter((i) => i.active))
const ob = ref(null), busy = ref(false), catOpen = ref(false), newCat = ref({ emoji: '📁', name: '' })
const f = ref({ type: 'loan', title: '', category_id: 0, creditor: '', count: 12, period_n: 1, mode: 'each', amount: 0, manual: '', rperiod: 'month:1',
  first_date: store.boot.today, paid_count: 0, shared: false, shared_user_id: others.value[0]?.id || 0, owner_share: 50, income_id: 0, note: '' })

const amounts = computed(() => {
  const x = f.value
  if (x.type !== 'loan') return []
  if (x.mode === 'each') return x.amount ? Array(x.count).fill(x.amount) : []
  if (x.mode === 'total') { if (!x.amount) return []; const e = Math.floor(x.amount / x.count); const a = Array(x.count).fill(e); a[x.count - 1] += x.amount - e * x.count; return a }
  const out = []
  for (let line of toEn(x.manual).split(/[\n+]+/)) {
    line = line.replace(/[,،٬\s]/g, ''); if (!line) continue
    const m = line.match(/^(\d+)(?:[*x×](\d+))?$/i); if (!m) return []
    for (let i = 0; i < (+m[2] || 1); i++) out.push(+m[1])
  }
  return out
})
const total = computed(() => amounts.value.reduce((s, v) => s + v, 0))
const dates = computed(() => {
  const [y, m, d] = fromG(f.value.first_date)
  return Array.from({ length: f.value.count }, (_, i) => { const [ny, nm] = shift(y, m, i * f.value.period_n); return toG(ny, nm, Math.min(d, monthLen(ny, nm))) })
})
const pastCount = computed(() => (f.value.type === 'loan' ? dates.value.filter((g) => g < today.value).length : 0))
watch(pastCount, (n) => { if (f.value.paid_count > n) f.value.paid_count = n })
const preview = computed(() => {
  const x = f.value
  if (x.type === 'loan' && amounts.value.length) {
    const l = [fa(amounts.value.length) + ' قسط، جمع ' + mil(total.value) + '، آخرین قسط ' + jfull(dates.value[dates.value.length - 1])]
    if (x.shared) l.push('سهم ماهانه شما: ' + toman(Math.round((amounts.value[0] * x.owner_share) / 100)))
    if (x.paid_count) l.push(fa(x.paid_count) + ' قسط اول پرداخت‌شده ثبت می‌شود')
    return l
  }
  if (x.type !== 'loan' && x.amount) {
    const [u, n] = x.rperiod.split(':')
    return [(x.type === 'recurring' ? period(u, +n) + '، ' : '') + toman(x.amount) + '، ' + (x.type === 'recurring' ? 'از ' : '') + jfull(x.first_date)]
  }
  return null
})
const valid = computed(() => {
  const x = f.value
  if (!x.title.trim()) return false
  if (editId.value) return true
  if (x.type === 'loan') return amounts.value.length === x.count && x.count > 0
  return x.amount > 0
})

async function submit() {
  if (!valid.value || busy.value) return
  busy.value = true; mainBtn()
  const x = f.value
  try {
    if (editId.value) {
      await act('ob_update', { id: editId.value, title: x.title, category_id: x.category_id, creditor: x.creditor, note: x.note, amount: x.amount && x.amount !== ob.value.amount ? x.amount : null }, 'ذخیره شد')
      router.back()
    } else {
      const [u, n] = x.rperiod.split(':')
      const body = { type: x.type, title: x.title, category_id: x.category_id, creditor: x.creditor, first_date: x.first_date, income_id: x.income_id,
        shared_user_id: x.shared ? x.shared_user_id : 0, owner_share: x.owner_share }
      if (x.type === 'loan') Object.assign(body, { amounts: amounts.value, period_n: x.period_n, paid_count: x.paid_count })
      else Object.assign(body, { amount: x.amount, period_unit: x.type === 'recurring' ? u : null, period_n: x.type === 'recurring' ? +n : 1 })
      const r = await act('create', body, 'ثبت شد')
      router.replace('/ob/' + r.id)
    }
  } catch (e) {} finally { busy.value = false; mainBtn() }
}
async function addCat() {
  try { const r = await act('cat_save', newCat.value, 'دسته اضافه شد'); catOpen.value = false; newCat.value = { emoji: '📁', name: '' }; setTimeout(() => (f.value.category_id = r.id), 300) } catch (e) {}
}
function mainBtn() { setMain(editId.value ? 'ذخیره' : 'ثبت', submit, { active: valid.value && !busy.value, loading: busy.value, color: valid.value ? '#34D399' : '#3A4250', textColor: valid.value ? '#06251A' : '#9AA3B2' }) }
watch([valid, busy], mainBtn)
onMounted(async () => {
  mainBtn()
  if (editId.value) {
    try {
      ob.value = (await api('ob', { id: editId.value })).ob
      Object.assign(f.value, { type: ob.value.type, title: ob.value.title, category_id: ob.value.cat || 0, creditor: ob.value.creditor || '', note: ob.value.note || '', amount: 0 })
    } catch (e) { toast(errMsg(e), 'err'); router.back() }
  }
})
onBeforeUnmount(() => setMain(null, null))
</script>
