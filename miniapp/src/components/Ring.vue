<template>
  <div :style="{ position: 'relative', width: size + 'px', height: size + 'px', flexShrink: 0 }">
    <svg role="img" :aria-label="label" :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`">
      <circle :cx="c" :cy="c" :r="r" fill="none" :stroke="track" :stroke-width="stroke" />
      <circle v-if="value > 0" :cx="c" :cy="c" :r="r" fill="none" :stroke="color" :stroke-width="stroke" stroke-linecap="round"
        :stroke-dasharray="`${dash} ${circ}`" :transform="`rotate(-90 ${c} ${c})`" style="transition: stroke-dasharray .6s" />
    </svg>
    <div style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center"><slot /></div>
  </div>
</template>
<script setup>
import { computed } from 'vue'
const p = defineProps({ value: Number, size: { type: Number, default: 84 }, stroke: { type: Number, default: 9 }, color: { type: String, default: 'var(--blue)' }, track: { type: String, default: 'var(--mint-soft)' }, label: String })
const c = computed(() => p.size / 2)
const r = computed(() => p.size / 2 - p.stroke / 2 - 1)
const circ = computed(() => 2 * Math.PI * r.value)
const dash = computed(() => Math.max(0.001, Math.min(1, p.value || 0)) * circ.value)
</script>
