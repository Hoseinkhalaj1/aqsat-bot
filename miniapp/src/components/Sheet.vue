<template>
  <Teleport to="body">
    <transition name="sheet">
      <div v-if="modelValue" class="ov" @click.self="close">
        <div class="sh" role="dialog" :aria-label="title">
          <div class="grab"></div>
          <div class="between" style="margin-bottom: 12px">
            <h2>{{ title }}</h2>
            <button class="ibtn" style="width: 40px; height: 40px" aria-label="بستن" @click="close"><Icon name="x" :size="18" /></button>
          </div>
          <slot />
        </div>
      </div>
    </transition>
  </Teleport>
</template>
<script setup>
import Icon from './Icon.vue'
defineProps({ modelValue: Boolean, title: String })
const emit = defineEmits(['update:modelValue'])
const close = () => emit('update:modelValue', false)
</script>
<style>
.ov { position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 60; display: flex; align-items: flex-end; justify-content: center; }
.sh { width: 100%; max-width: 560px; max-height: 88vh; overflow-y: auto; background: var(--bg); border-radius: 24px 24px 0 0; padding: 8px 16px calc(20px + env(safe-area-inset-bottom)); border-top: 1px solid var(--line); }
.grab { width: 40px; height: 4px; border-radius: 4px; background: var(--line2); margin: 4px auto 10px; }
.sheet-enter-active, .sheet-leave-active { transition: opacity .2s; }
.sheet-enter-active .sh, .sheet-leave-active .sh { transition: transform .25s cubic-bezier(.2,.8,.2,1); }
.sheet-enter-from, .sheet-leave-to { opacity: 0; }
.sheet-enter-from .sh, .sheet-leave-to .sh { transform: translateY(100%); }
</style>
