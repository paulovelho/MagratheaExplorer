import { createRouter, createWebHistory } from 'vue-router'
import { getKey } from '../api/client'
import LoginView from '../views/LoginView.vue'
import ExplorerView from '../views/ExplorerView.vue'

const routes = [
  { path: '/', redirect: '/login' },
  { path: '/login', name: 'login', component: LoginView },
  {
    path: '/folder/:id?',
    name: 'explorer',
    component: ExplorerView,
    props: true,
  },
]

const router = createRouter({
  history: createWebHistory('/app/'),
  routes,
})

router.beforeEach((to) => {
  const hasKey = !!getKey()
  if (to.name !== 'login' && !hasKey) {
    return { name: 'login' }
  }
  if (to.name === 'login' && hasKey) {
    return { name: 'explorer' }
  }
  return true
})

export default router
