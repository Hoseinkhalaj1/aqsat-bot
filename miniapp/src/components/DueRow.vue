<template>
  <div class="lrow" role="button" tabindex="0" @click="$emit('open', g)" @keydown.enter="$emit('open', g)">
    <div class="stat" :class="st">
      <Icon v-if="st === 'paid'" name="check" :size="16" :stroke="2.4" />
      <Icon v-else-if="st === 'over'" name="alert" :size="16" />
    </div>
    <div class="grow">
      <div class="b ell" style="font-size: 14px">{{ g.title }}<span v-if="g.count > 1"> × {{ fa(g.count) }}</span><span class="lbl">{{ shareText(g) }}</span></div>
      <div class="lbl" :class="{ 'c-mint': st === 'today', 'c-red': st === 'over' }">{{ g.dateText }}، {{ stText }}</div>
    </div>
    <div class="col" style="align-items: flex-end">
      <div class="b" style="font-size: 14px">{{ money(g.status === 'paid' ? g.mine : g.mine_left || g.mine) }}</div>
      <span v-if="g.source" class="chip blue"><Icon v-if="g.source.manual" name="link" :size="11" />{{ sourceText(g.source) }}</span>
      <span v-else class="chip" :class="{ amber: g.status !== 'paid' }">بدون منبع</span>
    </div>
  </div>
</template>
<script setup>
import { computed } from 'vue'
import Icon from './Icon.vue'
import { money, fa, rel } from '../fmt'
import { statusOf, sourceText, shareText } from './dues'
const p = defineProps({ g: Object, today: String })
defineEmits(['open'])
const st = computed(() => statusOf(p.g, p.today))
const stText = computed(() => ({ paid: 'پرداخت شد', over: 'معوقه', partial: 'پرداخت جزئی', today: 'امروز' }[st.value] || rel(p.today, p.g.items[p.g.items.length - 1].date)))
</script>
