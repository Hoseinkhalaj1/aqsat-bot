<template>
  <Sheet :model-value="!!due" :title="due ? due.title : ''" @update:model-value="close">
    <div v-if="due" class="col" style="gap: 14px">
      <div class="sub between">
        <div class="col"><span class="lbl">سررسید</span><b>{{ jfull(due.date) }}</b></div>
        <div class="col" style="align-items: flex-end"><span class="lbl">مانده</span><b>{{ toman(due.amount - due.paid) }}</b></div>
      </div>
      <div v-if="due.shared" class="lbl">سهم شما: {{ toman(due.mine_left) }} ({{ fa(due.share) }}٪)</div>
      <template v-if="!partial">
        <button class="btn mint block" :disabled="busy" @click="pay(null)"><Icon name="check" :size="18" />پرداخت کامل</button>
        <button class="btn block" @click="partial = true">پرداخت جزئی</button>
        <button class="btn ghost block" @click="go">جزئیات و همه اقساط</button>
      </template>
      <template v-else>
        <div class="field"><label for="pa">مبلغ پرداختی</label><MoneyInput id="pa" v-model="amount" /></div>
        <button class="btn mint block" :disabled="busy || !amount" @click="pay(amount)">ثبت پرداخت</button>
      </template>
    </div>
  </Sheet>
</template>
<script setup>
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Sheet from './Sheet.vue'
import Icon from './Icon.vue'
import MoneyInput from './MoneyInput.vue'
import { jfull, toman, fa } from '../fmt'
import { act, toast } from '../store'
const p = defineProps({ due: Object })
const emit = defineEmits(['close', 'paid'])
const router = useRouter()
const partial = ref(false), amount = ref(0), busy = ref(false)
watch(() => p.due, () => { partial.value = false; amount.value = 0 })
const close = () => emit('close')
function go() { const id = p.due.ob; close(); router.push('/ob/' + id) }
async function pay(a) {
  busy.value = true
  try {
    const r = await act('pay', { due_id: p.due.id, amount: a }, 'پرداخت ثبت شد')
    if (r.done) toast('🎉 همه اقساط «' + p.due.title + '» پرداخت شد')
    emit('paid'); close()
  } catch (e) {} finally { busy.value = false }
}
</script>
