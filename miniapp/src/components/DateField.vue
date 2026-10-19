<template>
  <button type="button" class="in" style="display: flex; align-items: center; justify-content: space-between; cursor: pointer" :aria-label="'تاریخ: ' + text" @click="open = true">
    <span>{{ text }}</span><Icon name="cal" :size="18" class="c-muted" />
  </button>
  <Sheet v-model="open" :title="title || 'انتخاب تاریخ'">
    <DatePicker :model-value="modelValue" :today="today" @update:model-value="(v) => emit('update:modelValue', v)" @picked="open = false" />
    <div class="row" style="margin-top: 12px"><button class="btn ghost sm" @click="emit('update:modelValue', today); open = false">امروز</button></div>
  </Sheet>
</template>
<script setup>
import { ref, computed } from 'vue'
import Icon from './Icon.vue'
import Sheet from './Sheet.vue'
import DatePicker from './DatePicker.vue'
import { jfull } from '../fmt'
const p = defineProps({ modelValue: String, today: String, title: String })
const emit = defineEmits(['update:modelValue'])
const open = ref(false)
const text = computed(() => (p.modelValue ? jfull(p.modelValue) : 'انتخاب کنید'))
</script>
