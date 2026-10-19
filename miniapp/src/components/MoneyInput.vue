<template>
  <div class="col">
    <div style="position: relative">
      <input class="in" :id="id" inputmode="numeric" autocomplete="off" :value="shown" :placeholder="placeholder" :aria-label="ariaLabel"
        style="font-size: 18px; font-weight: 800; padding-left: 64px" @input="onInput" />
      <span class="lbl" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%)">تومان</span>
    </div>
    <div v-if="modelValue" class="lbl">{{ words }}</div>
  </div>
</template>
<script setup>
import { computed } from 'vue'
import { money, mil, toEn } from '../fmt'
const p = defineProps({ modelValue: Number, id: String, placeholder: { type: String, default: '۰' }, ariaLabel: String })
const emit = defineEmits(['update:modelValue'])
const shown = computed(() => (p.modelValue ? money(p.modelValue) : ''))
const words = computed(() => (p.modelValue >= 1000 ? mil(p.modelValue).replace(' م', ' میلیون') + ' تومان' : ''))
function onInput(e) {
  const n = parseInt(toEn(e.target.value).replace(/[^\d]/g, '') || '0', 10)
  emit('update:modelValue', n || 0)
  e.target.value = n ? money(n) : ''
}
</script>
