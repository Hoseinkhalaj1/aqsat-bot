<template>
  <div class="page no-nav">
    <header class="head"><h1>تنظیمات</h1></header>

    <section class="card col" style="gap: 14px">
      <h2>یادآوری اقساط</h2>
      <div class="field">
        <div class="fh">چه زمانی یادآوری شود؟</div>
        <div class="chips">
          <button v-for="d in [7, 5, 3, 2, 1, 0]" :key="d" class="pill" :class="{ on: s.remind_days.includes(d) }" :aria-pressed="s.remind_days.includes(d)" @click="toggleDay(d)">{{ d ? fa(d) + ' روز قبل' : 'روز سررسید' }}</button>
        </div>
      </div>
      <div class="field"><label for="hr">ساعت ارسال (به وقت تهران)</label>
        <select id="hr" v-model.number="s.remind_hour" class="in" @change="save"><option v-for="h in 18" :key="h" :value="h + 5">{{ fa(String(h + 5).padStart(2, '0')) }}:۰۰</option></select></div>
      <div v-for="t in TOGGLES" :key="t.k" class="between">
        <div class="col"><b style="font-size: 14px">{{ t.t }}</b><span class="lbl">{{ t.h }}</span></div>
        <button class="switch" :class="{ on: s[t.k] }" :aria-pressed="s[t.k]" :aria-label="t.t" @click="s[t.k] = !s[t.k]; save()"></button>
      </div>
    </section>

    <section class="card col" style="gap: 12px">
      <div class="between"><h2>دسته‌بندی‌ها و بودجه</h2><button class="btn sm blue" @click="editCat(null)"><Icon name="plus" :size="16" />جدید</button></div>
      <button v-for="c in cats" :key="c.id" class="sub between" style="border: none; cursor: pointer; text-align: right" @click="editCat(c)">
        <span>{{ c.emoji }} <b style="font-size: 14px">{{ c.name }}</b></span>
        <span class="lbl">{{ c.budget ? 'سقف ماهانه ' + mil(c.budget) : 'بدون سقف' }}</span>
      </button>
    </section>

    <router-link to="/income" class="card between" style="color: var(--text)"><span class="row"><Icon name="wallet" class="c-mint" /><b>درآمدهای ثابت و برنامه</b></span><Icon name="chevL" :size="18" class="c-muted" /></router-link>

    <Sheet v-model="catOpen" :title="cf.id ? 'ویرایش دسته' : 'دسته جدید'">
      <div class="col" style="gap: 12px">
        <div class="row"><input v-model="cf.emoji" class="in" style="width: 64px; text-align: center" aria-label="ایموجی"><input v-model="cf.name" class="in grow" placeholder="نام دسته" aria-label="نام دسته"></div>
        <div class="field"><label for="bd">سقف بودجه ماهانه (اختیاری)</label><MoneyInput id="bd" v-model="cf.budget" /><span class="lbl">اگر پرداختی‌های ماه از سقف بگذرد، در ربات هشدار می‌گیرید.</span></div>
        <button class="btn mint block" :disabled="!cf.name" @click="saveCat">ذخیره</button>
        <button v-if="cf.id" class="btn red block" @click="delCat">حذف دسته</button>
      </div>
    </Sheet>
  </div>
</template>
<script setup>
import { ref, computed } from 'vue'
import Icon from '../components/Icon.vue'
import Sheet from '../components/Sheet.vue'
import MoneyInput from '../components/MoneyInput.vue'
import { store, act } from '../store'
import { ask } from '../tg'
import { fa, mil } from '../fmt'

const TOGGLES = [
  { k: 'overdue_daily', t: 'یادآوری روزانه معوقه‌ها', h: 'تا وقتی پرداخت ثبت نشده' },
  { k: 'monthly_summary', t: 'خلاصه اول هر ماه', h: 'فهرست پرداخت‌های ماه' },
  { k: 'income_remind', t: 'پرسش «واریز شد؟»', h: 'روز واریز هر درآمد ثابت' },
]
const me = store.boot.me
const s = ref({ remind_days: [...me.remind_days], remind_hour: me.remind_hour, overdue_daily: me.overdue_daily, monthly_summary: me.monthly_summary, income_remind: me.income_remind })
const cats = computed(() => store.boot.cats)
const catOpen = ref(false), cf = ref({})
function toggleDay(d) { const a = s.value.remind_days; s.value.remind_days = a.includes(d) ? a.filter((x) => x !== d) : [...a, d]; save() }
async function save() { try { await act('settings', s.value, 'ذخیره شد') } catch (e) {} }
function editCat(c) { cf.value = c ? { ...c } : { id: 0, emoji: '📁', name: '', budget: 0 }; catOpen.value = true }
async function saveCat() { try { await act('cat_save', cf.value, 'ذخیره شد'); catOpen.value = false } catch (e) {} }
async function delCat() { if (await ask('دسته «' + cf.value.name + '» حذف شود؟ موارد آن بدون دسته می‌شوند.')) try { await act('cat_del', { id: cf.value.id }, 'حذف شد'); catOpen.value = false } catch (e) {} }
</script>
