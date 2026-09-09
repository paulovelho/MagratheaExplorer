<script setup>
defineProps({
  path: { type: Array, required: true }, // [{ id, name }]
})

const emit = defineEmits(['navigate'])
</script>

<template>
  <nav class="breadcrumbs">
    <template v-for="(crumb, index) in path" :key="crumb.id ?? 'root'">
      <span v-if="index > 0" class="sep">/</span>
      <button
        class="crumb"
        :class="{ current: index === path.length - 1 }"
        :disabled="index === path.length - 1"
        @click="emit('navigate', crumb, index)"
      >
        {{ crumb.name }}
      </button>
    </template>
  </nav>
</template>

<style scoped>
.breadcrumbs {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.25rem;
  padding: 0.75rem 1.5rem 0;
  font-size: 0.9rem;
}

.crumb {
  background: none;
  border: none;
  padding: 0.15rem 0.3rem;
  color: #2d6cdf;
  font-size: 0.9rem;
  border-radius: 4px;
}

.crumb:hover:not(:disabled) {
  background: #eef2fb;
}

.crumb.current {
  color: #1a1a1a;
  font-weight: 600;
  cursor: default;
}

.sep {
  color: #999;
}
</style>
