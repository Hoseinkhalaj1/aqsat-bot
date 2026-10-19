<template>
  <div class="sw" :class="{ armed: dx <= -TH }">
    <div v-if="enabled" class="under" :style="{ opacity: Math.min(1, -dx / TH) }"><Icon name="check" :size="22" :stroke="2.4" /><span>{{ label }}</span></div>
    <div class="top" :style="{ transform: `translateX(${dx}px)`, transition: dragging ? 'none' : 'transform .25s' }"
      @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="cancel" @click.capture="guard">
      <slot />
    </div>
  </div>
</template>
<script setup>
import { ref } from 'vue'
import Icon from './Icon.vue'
import { tap, select } from '../tg'
const p = defineProps({ enabled: { type: Boolean, default: true }, label: { type: String, default: 'پرداخت شد' } })
const emit = defineEmits(['swiped'])
const TH = 90
const dx = ref(0), dragging = ref(false)
let x0 = 0, y0 = 0, horiz = null, moved = false, armedOnce = false
function down(e) { if (!p.enabled) return; x0 = e.clientX; y0 = e.clientY; horiz = null; moved = false; armedOnce = false; dragging.value = true }
function move(e) {
  if (!dragging.value) return
  const ddx = e.clientX - x0, ddy = e.clientY - y0
  if (horiz === null && (Math.abs(ddx) > 8 || Math.abs(ddy) > 8)) { horiz = Math.abs(ddx) > Math.abs(ddy); if (horiz) e.target.setPointerCapture?.(e.pointerId) }
  if (!horiz) return
  moved = true
  dx.value = Math.max(-140, Math.min(0, ddx))
  if (dx.value <= -TH && !armedOnce) { armedOnce = true; tap() }
  if (dx.value > -TH) armedOnce = false
}
function up() { if (!dragging.value) return; dragging.value = false; if (dx.value <= -TH) { select(); emit('swiped') } dx.value = 0 }
function cancel() { dragging.value = false; dx.value = 0 }
function guard(e) { if (moved) { e.stopPropagation(); e.preventDefault(); moved = false } }
</script>
<style scoped>
.sw { position: relative; border-radius: 20px; overflow: hidden; background: var(--mint); }
.under { position: absolute; top: 0; bottom: 0; left: 0; width: 120px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; color: var(--mint-ink); font-size: 12px; font-weight: 800; }
.top { position: relative; touch-action: pan-y; }
</style>
