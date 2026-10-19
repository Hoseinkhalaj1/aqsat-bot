<template>
  <Sheet :model-value="!!prompt" :title="occ ? 'از این واریز کنار بگذارید' : '«' + (prompt?.title || '') + '» واریز شد؟'" @update:model-value="close">
    <div v-if="prompt && !occ" class="col" style="gap: 14px">
      <div class="sub between"><span class="lbl">موعد: {{ jfull(prompt.date) }}</span><b>{{ toman(prompt.amount) }}</b></div>
      <div class="field"><label for="dep">مبلغ واریز شده</label><MoneyInput id="dep" v-model="amount" /></div>
      <button class="btn amber block" :disabled="busy || !amount" @click="deposit">ثبت واریز</button>
    </div>
    <div v-else-if="occ" class="col" style="gap: 12px">
      <div class="between"><span class="lbl">{{ occ.title }}، {{ ymName(occ.ym, true) }}</span><b>{{ toman(occ.amount) }}</b></div>
      <div v-for="it in open" :key="it.id" class="sub between">
        <div class="col"><b style="font-size: 14px">{{ it.title }}</b><span class="lbl">سررسید {{ jd(it.date) }}، {{ rel(today, it.date) }}</span></div>
        <b>{{ money(it.alloc) }}</b>
      </div>
      <div v-if="!open.length" class="empty">قسط پرداخت‌نشده‌ای به این واریز وصل نیست؛ همه‌اش آزاد است.</div>
      <div class="g2">
        <div class="card tight col"><span class="lbl">کنار بگذار</span><b class="c-blue">{{ toman(openSum) }}</b></div>
        <div class="card tight col"><span class="lbl">{{ occ.free >= 0 ? 'قابل خرج' : 'کسری' }}</span><b :class="occ.free >= 0 ? 'c-mint' : 'c-red'">{{ toman(Math.abs(occ.free)) }}</b></div>
      </div>
      <button v-if="open.length && !occ.set_aside" class="btn mint block" :disabled="busy" @click="aside"><Icon name="check" :size="18" />کنار گذاشتم</button>
      <button v-else class="btn block" @click="close">بستن</button>
    </div>
  </Sheet>
</template>
<script setup>
import { ref, computed, watch } from 'vue'
import Sheet from './Sheet.vue'
import Icon from './Icon.vue'
import MoneyInput from './MoneyInput.vue'
import { jfull, jd, rel, toman, money, ymName } from '../fmt'
import { act, store } from '../store'
const p = defineProps({ prompt: Object, quick: Boolean })
const emit = defineEmits(['close'])
const amount = ref(0), occ = ref(null), busy = ref(false)
const today = computed(() => store.boot.today)
watch(() => p.prompt, async (v) => {
  occ.value = null; amount.value = v?.amount || 0
  if (v && p.quick) await deposit()
})
const open = computed(() => (occ.value?.items || []).filter((i) => i.status !== 'paid'))
const openSum = computed(() => open.value.reduce((s, i) => s + i.alloc, 0))
const close = () => emit('close')
async function deposit() {
  busy.value = true
  try { const r = await act('deposit', { income_id: p.prompt.income_id, ym: p.prompt.ym, amount: amount.value }, 'واریز ثبت شد'); occ.value = r.occ } catch (e) {} finally { busy.value = false }
}
async function aside() {
  busy.value = true
  try { await act('set_aside', { income_id: p.prompt.income_id, ym: p.prompt.ym }, 'کنار گذاشته شد'); close() } catch (e) {} finally { busy.value = false }
}
</script>
