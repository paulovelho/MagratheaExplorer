<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { setKey, clearKey } from '../api/client'
import { fetchKey } from '../api/key'

const router = useRouter()
const keyInput = ref('')
const error = ref('')
const loading = ref(false)

// The `autofocus` HTML attribute only fires on the initial page parse, not
// when vue-router mounts this view client-side (e.g. after a logout
// redirect) -- this directive focuses it in both cases.
const vFocus = {
  mounted: (el) => el.focus(),
}

async function submit() {
  const trimmed = keyInput.value.trim()
  if (!trimmed) {
    error.value = 'Enter a key.'
    return
  }
  error.value = ''
  loading.value = true
  try {
    // Set the key first so fetchKey's Authorization header picks it up;
    // roll back on failure so an invalid key doesn't linger in storage.
    setKey(trimmed)
    await fetchKey()
    router.push({ name: 'explorer' })
  } catch {
    clearKey()
    error.value = 'Invalid or expired key.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-screen">
    <form class="login-card" @submit.prevent="submit">
      <h1>Magrathea Explorer</h1>
      <p class="subtitle">Enter your access key to continue.</p>
      <input
        v-model="keyInput"
        v-focus
        type="text"
        placeholder="Access key"
        :disabled="loading"
      />
      <p v-if="error" class="error">{{ error }}</p>
      <button type="submit" :disabled="loading">
        {{ loading ? 'Checking…' : 'Continue' }}
      </button>
    </form>
  </div>
</template>

<style scoped>
.login-screen {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
}

.login-card {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
  padding: 2.5rem;
  width: 100%;
  max-width: 360px;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

h1 {
  margin: 0;
  font-size: 1.4rem;
}

.subtitle {
  margin: 0 0 0.5rem;
  color: #666;
  font-size: 0.9rem;
}

input {
  padding: 0.6rem 0.8rem;
  border: 1px solid #ccc;
  border-radius: 8px;
  font-size: 1rem;
}

button {
  padding: 0.6rem 0.8rem;
  border: none;
  border-radius: 8px;
  background: #2d6cdf;
  color: #fff;
  font-size: 1rem;
  font-weight: 600;
}

button:disabled {
  opacity: 0.6;
  cursor: default;
}

.error {
  color: #c0392b;
  font-size: 0.85rem;
  margin: 0;
}
</style>
