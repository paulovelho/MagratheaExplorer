import { createRouter, createWebHistory } from 'vue-router'
import { getKey } from '../api/client'
import LoginView from '../views/LoginView.vue'
import ExplorerView from '../views/ExplorerView.vue'
import SharesView from '../views/SharesView.vue'
import SharedView from '../views/SharedView.vue'

const routes = [
  { path: '/', redirect: '/login' },
  { path: '/login', name: 'login', component: LoginView },
  {
    path: '/folder/:uuid?',
    name: 'explorer',
    component: ExplorerView,
    props: true,
  },
  { path: '/shares', name: 'shares', component: SharesView },
  {
    // The public share page. `meta.public` is what keeps the guard below from
    // bouncing a keyless visitor to /login.
    path: '/s/:uuid/:folderUuid?',
    name: 'shared',
    component: SharedView,
    props: true,
    meta: { public: true },
  },
]

const router = createRouter({
  history: createWebHistory('/app/'),
  routes,
})

router.beforeEach((to) => {
  // Before the key check, not after: a share link must work with no key stored at all.
  if (to.meta.public) return true
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
