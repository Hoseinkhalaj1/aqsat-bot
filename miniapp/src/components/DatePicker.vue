<template>
  <div class="col" style="gap: 10px">
    <div class="between">
      <button class="ibtn" aria-label="ماه قبل" @click="move(-1)"><Icon name="chevR" :size="18" /></button>
      <b>{{ MONTHS[vm] }} {{ fa(vy) }}</b>
      <button class="ibtn" aria-label="ماه بعد" @click="move(1)"><Icon name="chevL" :size="18" /></button>
    </div>
    <div class="grid lbl" style="text-align: center"><div v-for="w in WD" :key="w">{{ w }}</div></div>
    <div class="grid">
      <div v-for="i in blanks" :key="'b' + i"></div>
      <button v-for="d in days" :key="d" class="dd" :class="{ on: isSel(d), today: isToday(d) }" @click="pick(d)">{{ fa(d) }}</button>
    </div>
  </div>
</template>
<script setup>
import { ref, computed } from 'vue'
import Icon from './Icon.vue'
import { MONTHS, fromG, toG, monthLen, satIndex, shift } from '../jalali'
import { fa } from '../fmt'
import { select } from '../tg'
const p = defineProps({ modelValue: String, today: String })
const emit = defineEmits(['update:modelValue', 'picked'])
const WD = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج']
const init = fromG(p.modelValue || p.today)
const vy = ref(init[0]), vm = ref(init[1])
const blanks = computed(() => satIndex(vy.value, vm.value, 1))
const days = computed(() => monthLen(vy.value, vm.value))
const move = (n) => { [vy.value, vm.value] = shift(vy.value, vm.value, n) }
const isSel = (d) => p.modelValue === toG(vy.value, vm.value, d)
const isToday = (d) => p.today === toG(vy.value, vm.value, d)
function pick(d) { select(); const g = toG(vy.value, vm.value, d); emit('update:modelValue', g); emit('picked', g) }
</script>
<style scoped>
.grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
.dd { height: 44px; border-radius: 12px; border: 1.5px solid transparent; background: var(--card); font-size: 14px; font-weight: 700; cursor: pointer; }
.dd.today { border-color: var(--mint); }
.dd.on { background: var(--mint); color: var(--mint-ink); }
</style>
