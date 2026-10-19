<template>
  <div class="page no-nav">
    <template v-if="d">
      <header class="col" style="gap: 6px">
        <div class="row" style="gap: 6px; flex-wrap: wrap">
          <span class="chip" :class="ob.type === 'loan' ? 'violet' : 'blue'">{{ ob.type === 'recurring' ? period(ob.period_unit, ob.period_n) : TYPE[ob.type] }}</span>
          <span v-if="cat" class="chip">{{ cat.emoji }} {{ cat.name }}</span>
          <span class="chip" :class="{ mint: ob.status === 'done' }">{{ { active: 'فعال', done: 'تسویه شد', stopped: 'متوقف' }[ob.status] }}</span>
        </div>
        <h1>{{ ob.title }}</h1>
        <div v-if="ob.creditor" class="lbl">طرف حساب: {{ ob.creditor }}</div>
        <div v-if="ob.shared" class="lbl"><Icon name="users" :size="13" /> مشترک با {{ ob.partner }}، سهم شما {{ fa(ob.share) }}٪</div>
      </header>

      <section class="card row" style="gap: 16px">
        <Ring v-if="ob.type === 'loan'" :value="ob.total ? ob.paid / ob.total : 0" :size="96" :stroke="10" color="var(--violet)" track="var(--track)" label="پیشرفت">
          <b style="font-size: 18px">{{ fa(Math.round(ob.total ? (ob.paid / ob.total) * 100 : 0)) }}٪</b><span class="lbl" style="font-size: 10px">{{ fa(ob.paid_cnt) }} از {{ fa(ob.cnt) }}</span>
        </Ring>
        <div class="col grow" style="gap: 8px">
          <template v-if="ob.type === 'loan'">
            <div class="between"><span class="lbl">مبلغ کل</span><b>{{ money(ob.total) }}</b></div>
            <div class="between"><span class="lbl">پرداخت‌شده</span><b class="c-blue">{{ money(ob.paid) }}</b></div>
            <div class="between"><span class="lbl">مانده</span><b>{{ money(ob.left) }}</b></div>
            <div v-if="ob.shared" class="between"><span class="lbl">سهم شما از مانده</span><b class="c-mint">{{ money(ob.left_mine) }}</b></div>
            <div v-if="ob.last_date" class="between"><span class="lbl">آخرین قسط</span><b>{{ jfull(ob.last_date) }}</b></div>
          </template>
          <template v-else>
            <div class="between"><span class="lbl">{{ ob.type === 'recurring' ? 'مبلغ هر دوره' : 'مبلغ' }}</span><b>{{ money(ob.type === 'recurring' ? ob.amount : ob.total) }}</b></div>
            <div class="between"><span class="lbl">پرداخت‌شده</span><b class="c-blue">{{ fa(ob.paid_cnt) }} بار، {{ mil(ob.paid) }}</b></div>
          </template>
        </div>
      </section>

      <section v-if="ob.next" class="card col" style="gap: 12px">
        <div class="between">
          <div class="col"><span class="lbl" :class="{ 'c-red': ob.next.overdue }">پرداخت بعدی، {{ weekday(ob.next.date) }} {{ jfull(ob.next.date) }}، {{ rel(today, ob.next.date) }}</span>
            <b style="font-size: 20px">{{ toman(ob.next.amount - ob.next.paid) }}</b>
            <span v-if="ob.shared" class="lbl">سهم شما {{ toman(ob.next.mine_left) }}</span></div>
        </div>
        <div class="row"><button class="btn mint grow" @click="pay(ob.next)">پرداخت</button><button class="btn grow" @click="payDue = ob.next">جزئی</button></div>
      </section>

      <button class="card row" style="text-align: right; cursor: pointer; color: var(--text)" @click="srcOpen = true">
        <Icon name="link" class="c-violet" />
        <div class="col grow"><b style="font-size: 14px">از کدام دریافتی پرداخت شود؟</b><span class="lbl">{{ ob.source ? ob.source.title + ' (دستی)' : 'خودکار' }}</span></div>
        <Icon name="chevL" :size="18" class="c-muted" />
      </button>

      <div class="row" style="flex-wrap: wrap; gap: 8px">
        <button v-if="ob.type === 'loan' && ob.status === 'active'" class="btn blue" @click="settle" style="flex: 1 1 auto; white-space: nowrap"><Icon name="flag" :size="18" />تسویه کامل</button>
        <button v-if="ob.type === 'recurring' && ob.owner" class="btn blue" @click="toggleStop" style="flex: 1 1 auto; white-space: nowrap"><Icon :name="ob.status === 'active' ? 'pause' : 'play'" :size="18" />{{ ob.status === 'active' ? 'توقف' : 'ادامه' }}</button>
        <router-link v-if="ob.owner" :to="'/edit/' + ob.id" class="btn" style="flex: 1 1 auto; white-space: nowrap"><Icon name="edit" :size="18" />ویرایش</router-link>
        <button v-if="ob.owner" class="btn red" @click="del" style="flex: 1 1 auto; white-space: nowrap"><Icon name="trash" :size="18" />حذف</button>
      </div>

      <section class="card list">
        <div class="between" style="padding: 12px 0"><h2>{{ ob.type === 'loan' ? 'اقساط' : 'سوابق و پرداخت‌های آینده' }}</h2><span class="lbl">{{ fa(d.dues.length) }} مورد</span></div>
        <div v-for="x in shownDues" :key="x.id" class="lrow" @click="dueSel = x">
          <div class="stat" :class="stOf(x)"><Icon v-if="x.status === 'paid'" name="check" :size="16" :stroke="2.4" /><Icon v-else-if="x.overdue" name="alert" :size="16" /></div>
          <div class="grow col" style="gap: 2px">
            <b style="font-size: 14px">{{ ob.type === 'loan' ? 'قسط ' + fa(x.seq) : jd(x.date) }}</b>
            <span class="lbl">{{ jfull(x.date) }}<template v-if="x.status === 'partial'">، پرداخت‌شده {{ money(x.paid) }}</template><template v-if="x.payments.length && x.status === 'paid'">، {{ jd(x.payments[x.payments.length - 1].at) }}</template></span>
          </div>
          <b style="font-size: 14px">{{ money(x.amount) }}</b>
        </div>
        <button v-if="d.dues.length > limit" class="btn ghost block" style="margin: 8px 0 12px" @click="limit += 24">نمایش بیشتر</button>
      </section>
      <div v-if="ob.note" class="card lbl">{{ ob.note }}</div>
    </template>
    <div v-else class="skel" style="height: 300px"></div>

    <PaySheet :due="payDue" @close="payDue = null" />
    <Sheet :model-value="!!dueSel" :title="dueSel ? (ob.type === 'loan' ? 'قسط ' + fa(dueSel.seq) : jfull(dueSel.date)) : ''" @update:model-value="dueSel = null">
      <div v-if="dueSel" class="col" style="gap: 12px">
        <div class="sub between"><span class="lbl">{{ jfull(dueSel.date) }}</span><b>{{ toman(dueSel.amount) }}</b></div>
        <div v-for="(p, i) in dueSel.payments" :key="i" class="between lbl"><span>{{ jfull(p.at) }}، {{ p.by }}</span><b>{{ money(p.amount) }}</b></div>
        <div class="g2">
          <button v-if="dueSel.status !== 'paid'" class="btn mint" @click="pay(dueSel); dueSel = null">پرداخت کامل</button>
          <button v-if="dueSel.status !== 'paid'" class="btn" @click="payDue = dueSel; dueSel = null">جزئی</button>
          <button v-if="dueSel.payments.length" class="btn" @click="undo(dueSel)"><Icon name="undo" :size="18" />لغو آخرین پرداخت</button>
        </div>
        <template v-if="ob.owner">
          <div class="field"><label for="na">تغییر مبلغ این مورد</label><MoneyInput id="na" v-model="edAmount" /></div>
          <div class="field"><div class="fh">تغییر تاریخ</div><DateField v-model="edDate" :today="today" /></div>
          <button class="btn blue block" @click="saveDue">ذخیره تغییرات</button>
        </template>
      </div>
    </Sheet>
    <Sheet v-model="srcOpen" title="منبع پرداخت">
      <div class="col" style="gap: 8px">
        <button class="pill" :class="{ on: !ob?.source }" style="text-align: right" @click="setSrc(0)">خودکار (طبق حالت کلی)</button>
        <button v-for="i in incomes" :key="i.id" class="pill" :class="{ on: ob?.source?.id === i.id }" style="text-align: right" @click="setSrc(i.id)">{{ i.title }}، روز {{ fa(i.day) }}</button>
      </div>
    </Sheet>
  </div>
</template>
<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import Ring from '../components/Ring.vue'
import Sheet from '../components/Sheet.vue'
import PaySheet from '../components/PaySheet.vue'
import MoneyInput from '../components/MoneyInput.vue'
import DateField from '../components/DateField.vue'
import { api } from '../api'
import { store, act, toast, errMsg } from '../store'
import { ask } from '../tg'
import { fa, money, toman, mil, jd, jfull, rel, weekday, period, TYPE } from '../fmt'

const route = useRoute(), router = useRouter()
const d = ref(null), payDue = ref(null), dueSel = ref(null), srcOpen = ref(false), limit = ref(24)
const edAmount = ref(0), edDate = ref('')
const ob = computed(() => d.value?.ob)
const today = computed(() => store.boot.today)
const cat = computed(() => store.boot.cats.find((c) => c.id === ob.value?.cat))
const incomes = computed(() => store.boot.incomes.filter((i) => i.active))
const shownDues = computed(() => {
  const all = d.value.dues
  if (ob.value.type !== 'recurring') return all.slice(0, limit.value)
  const past = all.filter((x) => x.date <= today.value || x.status === 'paid')
  const fut = all.filter((x) => !(x.date <= today.value || x.status === 'paid')).slice(0, 3)
  return [...fut, ...past.reverse()].slice(0, limit.value)
})
watch(dueSel, (v) => { if (v) { edAmount.value = v.amount; edDate.value = v.date } })
async function load() { try { d.value = await api('ob', { id: +route.params.id }) } catch (e) { toast(errMsg(e), 'err'); router.replace('/obs') } }
onMounted(load)
watch(() => store.rev, load)
const stOf = (x) => (x.status === 'paid' ? 'paid' : x.overdue ? 'over' : x.status === 'partial' ? 'partial' : 'pending')
async function pay(x) { try { const r = await act('pay', { due_id: x.id }, 'پرداخت ثبت شد'); if (r.done) toast('🎉 همه اقساط پرداخت شد') } catch (e) {} }
async function undo(x) { dueSel.value = null; try { await act('undo', { due_id: x.id }, 'آخرین پرداخت لغو شد') } catch (e) {} }
async function saveDue() {
  const x = dueSel.value; dueSel.value = null
  try { await act('due_update', { id: x.id, amount: edAmount.value !== x.amount ? edAmount.value : null, date: edDate.value !== x.date ? edDate.value : null }, 'ذخیره شد') } catch (e) {}
}
async function settle() { if (await ask('مانده «' + ob.value.title + '» (' + toman(ob.value.left) + ') پرداخت‌شده ثبت شود؟')) try { await act('settle', { id: ob.value.id }, 'تسویه شد') } catch (e) {} }
async function toggleStop() { try { await act(ob.value.status === 'active' ? 'stop' : 'resume', { id: ob.value.id }, ob.value.status === 'active' ? 'متوقف شد' : 'فعال شد') } catch (e) {} }
async function del() { if (await ask('«' + ob.value.title + '» و همه سوابقش حذف شود؟')) try { await act('del', { id: ob.value.id }, 'حذف شد'); router.replace('/obs') } catch (e) {} }
async function setSrc(id) { srcOpen.value = false; try { await act('assign', { ob_id: ob.value.id, income_id: id }, 'ذخیره شد') } catch (e) {} }
</script>
<style scoped>.c-violet { color: var(--violet); }</style>
