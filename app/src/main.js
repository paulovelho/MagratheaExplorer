import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { setUnauthorizedHandler } from './api/client'

setUnauthorizedHandler(() => {
  if (router.currentRoute.value.name !== 'login') {
    router.push({ name: 'login' })
  }
})

createApp(App).use(router).mount('#app')
