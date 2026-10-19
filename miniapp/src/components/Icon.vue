<template>
  <svg aria-hidden="true" :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="w" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0"><path :d="P[name] || ''" /></svg>
</template>
<script setup>
import { computed } from 'vue'
const props = defineProps({ name: String, size: { type: Number, default: 22 }, stroke: Number })
const DOTS = ['more', 'drag']
const w = computed(() => props.stroke || (DOTS.includes(props.name) ? 3 : 1.8))
const P = {
  home: 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
  list: 'M9 6h12M9 12h12M9 18h12M4 6h.01M4 12h.01M4 18h.01',
  wallet: 'M5 6h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2zM3 10h18M16 15h2',
  chart: 'M4 20V11M10 20V5M16 20v-6M21 20H3',
  cal: 'M6 5h12a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V8a3 3 0 0 1 3-3zM3 10h18M8 3v4M16 3v4',
  plus: 'M12 5v14M5 12h14', check: 'm5 12 5 5 9-10', x: 'M6 6l12 12M18 6 6 18',
  chevR: 'm9 6 6 6-6 6', chevL: 'm15 6-6 6 6 6', chevD: 'm6 9 6 6 6-6',
  gear: 'M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1M15 4v4M9 10v4M17 16v4',
  download: 'M12 4v11M7 10l5 5 5-5M5 20h14', send: 'M21 3 3 10l7 3 3 7z',
  more: 'M5 12h.01M12 12h.01M19 12h.01', drag: 'M9 6h.01M15 6h.01M9 12h.01M15 12h.01M9 18h.01M15 18h.01',
  repeat: 'M4 12a8 8 0 0 1 14-5.3M20 12a8 8 0 0 1-14 5.3M18 3v4h-4M6 21v-4h4',
  link: 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1',
  trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3', edit: 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
  alert: 'M12 9v4M12 17h.01M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
  shield: 'M12 3 4 6v6c0 4.5 3.4 8 8 9 4.6-1 8-4.5 8-9V6z', pin: 'M12 21s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12zM12 11.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  pause: 'M8 5v14M16 5v14', play: 'M7 5v14l11-7z', flag: 'M5 21V4h11l-2 4 2 4H5', undo: 'M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',
  users: 'M16 20v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 20v-1a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
  bell: 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10 21a2 2 0 0 0 4 0', tag: 'M3 12V4h8l10 10-8 8zM7.5 7.5h.01',
}
</script>
