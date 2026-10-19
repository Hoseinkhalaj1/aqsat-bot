<template>
  <div class="page">
    <header class="head"><h1>اقساط و پرداخت‌ها</h1><router-link to="/add" class="ibtn" aria-label="افزودن"><Icon name="plus" /></router-link></header>
    <div class="chips" role="group" aria-label="فیلتر">
      <button v-for="f in filters" :key="f.k" class="pill solid" :class="{ on: flt === f.k }" :aria-pressed="flt === f.k" @click="setF(f.k)">{{ f.t }} {{ fa(f.n) }}</button>
    </div>
    <template v-if="data">
      <div v-if="!list.length" class="card empty">موردی نیست.</div>
      <Swipe v-for="o in list" :key="o.id" :enabled="!!o.next" @swiped="quickPay(o)">
        <article class="card col" style="gap: 12px; border-radius: 20px" role="button" tabindex="0" @click="$router.push('/ob/' + o.id)" @keydown.enter="$router.push('/ob/' + o.id)">
          <div class="row" style="gap: 12px">
            <Ring v-if="o.type === 'loan'" :value="o.cnt ? o.paid_cnt / o.cnt : 0" :size="58" :stroke="6" color="var(--violet)" track="var(--track)" :label="fa(o.paid_cnt) + ' از ' + fa(o.cnt)">
              <b style="font-size: 12px">{{ fa(o.paid_cnt) }}/{{ fa(o.cnt) }}</b>
            </Ring>
            <div v-else class="ico" :class="o.type"><Icon :name="o.type === 'recurring' ? 'repeat' : 'flag'" /></div>
            <div class="col grow">
              <b class="ell" style="font-size: 16px">{{ o.title }}</b>
              <div class="row" style="gap: 6px; flex-wrap: wrap">
                <span class="chip" :class="o.type === 'loan' ? 'violet' : 'blue'">{{ o.type === 'recurring' ? period(o.period_unit, o.period_n) : TYPE[o.type] }}</span>
                <span v-if="o.shared" class="chip"><Icon name="users" :size="11" />سهم شما {{ fa(o.share) }}٪</span>
                <span v-if="o.status === 'done'" class="chip mint">تسویه شد</span>
                <span v-if="o.status === 'stopped'" class="chip">متوقف</span>
              </div>
            </div>
          </div>
          <div v-if="o.type === 'loan' && o.cnt <= 36" class="sqs" :style="{ gridTemplateColumns: `repeat(${Math.min(o.cnt, 12)}, minmax(0, 1fr))` }">
            <div v-for="i in o.cnt" :key="i" :style="{ background: i <= o.paid_cnt ? 'var(--violet)' : 'var(--track)' }"></div>
          </div>
          <div v-if="o.type === 'loan'" class="between lbl"><span>مانده {{ mil(o.left) }}<template v-if="o.shared">، سهم شما {{ mil(o.left_mine) }}</template></span><span v-if="o.last_date">پایان {{ jfull(o.last_date) }}</span></div>
          <div v-if="o.next" class="sub between">
            <div class="col">
              <span class="lbl" :class="{ 'c-red': o.next.overdue, 'c-mint': o.next.date === today }">{{ o.type === 'loan' ? 'قسط بعدی' : 'بعدی' }}، {{ jd(o.next.date) }}، {{ rel(today, o.next.date) }}</span>
              <b style="font-size: 15px">{{ money(o.next.amount - o.next.paid) }}</b>
            </div>
            <span class="chip" :class="o.source ? 'violet' : ''"><Icon v-if="o.source" name="link" :size="11" />{{ o.source ? 'از ' + o.source.title : 'خودکار' }}</span>
          </div>
          <div v-if="o.next" class="row" @click.stop>
            <button class="btn mint grow sm" @click="quickPay(o)">{{ o.type === 'loan' ? 'پرداخت قسط' : 'پرداخت شد' }}</button>
            <button class="btn sm" @click="payDue = o.next">جزئی / بیشتر</button>
          </div>
        </article>
      </Swipe>
      <div v-if="list.some((o) => o.next)" class="lbl" style="text-align: center">برای ثبت سریع پرداخت، کارت را به چپ بکشید</div>
    </template>
    <div v-else class="skel" style="height: 260px"></div>
    <PaySheet :due="payDue" @close="payDue = null" />
  </div>
</template>
<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import Ring from '../components/Ring.vue'
import Swipe from '../components/Swipe.vue'
import PaySheet from '../components/PaySheet.vue'
import { api } from '../api'
import { store, act, toast, errMsg } from '../store'
import { fa, money, mil, jd, jfull, rel, period, TYPE } from '../fmt'

const route = useRoute(), router = useRouter()
const data = ref(null), payDue = ref(null)
const flt = ref(route.query.f || 'all')
const today = computed(() => store.boot.today)
async function load() { try { data.value = await api('obs') } catch (e) { toast(errMsg(e), 'err') } }
onMounted(load)
watch(() => store.rev, load)
const act_ = computed(() => data.value?.active || [])
const filters = computed(() => [
  { k: 'all', t: 'همه', n: act_.value.length },
  { k: 'loan', t: 'وام‌ها', n: act_.value.filter((o) => o.type === 'loan').length },
  { k: 'recurring', t: 'دوره‌ای', n: act_.value.filter((o) => o.type === 'recurring').length },
  { k: 'once', t: 'یک‌باره', n: act_.value.filter((o) => o.type === 'once').length },
  { k: 'overdue', t: 'معوقه', n: act_.value.filter((o) => o.next?.overdue).length },
  { k: 'arch', t: 'بایگانی', n: data.value?.archived.length || 0 },
])
const list = computed(() => {
  if (!data.value) return []
  if (flt.value === 'arch') return data.value.archived
  if (flt.value === 'overdue') return act_.value.filter((o) => o.next?.overdue)
  return flt.value === 'all' ? act_.value : act_.value.filter((o) => o.type === flt.value)
})
function setF(k) { flt.value = k; router.replace({ query: k === 'all' ? {} : { f: k } }) }
async function quickPay(o) {
  if (!o.next) return
  try { const r = await act('pay', { due_id: o.next.id }, '«' + o.title + '» پرداخت شد'); if (r.done) toast('🎉 همه اقساط «' + o.title + '» پرداخت شد') } catch (e) {}
}
</script>
<style scoped>
.ico { width: 52px; height: 52px; border-radius: 16px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ico.recurring { background: var(--blue-soft); color: var(--blue-text); }
.ico.once { background: var(--amber-soft); color: var(--amber); }
.sqs { display: grid; gap: 3px; }
.sqs > div { height: 8px; border-radius: 3px; }
</style>
