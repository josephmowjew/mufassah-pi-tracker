import assert from 'node:assert/strict'
import { after, afterEach, before, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import axios from 'axios'
import { createServer } from 'vite'

const root = fileURLToPath(new URL('../', import.meta.url))
const storage = new Map()
const originalStorage = Object.getOwnPropertyDescriptor(globalThis, 'localStorage')
let server
let apiClient
let queryClient
let useAuthStore

before(async () => {
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (key) => storage.get(key) ?? null,
      setItem: (key, value) => storage.set(key, String(value)),
      removeItem: (key) => storage.delete(key),
    },
  })
  server = await createServer({
    root,
    configFile: false,
    resolve: { alias: { '@': fileURLToPath(new URL('../src', import.meta.url)) } },
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    appType: 'custom',
    optimizeDeps: { noDiscovery: true },
    define: { 'import.meta.env.VITE_API_URL': JSON.stringify('/api/v1') },
  })
  ;({ apiClient } = await server.ssrLoadModule('/src/api/client.ts'))
  ;({ queryClient } = await server.ssrLoadModule('/src/lib/query-client.ts'))
  ;({ useAuthStore } = await server.ssrLoadModule('/src/stores/auth-store.ts'))
})

afterEach(() => {
  useAuthStore.getState().logout()
  queryClient.clear()
  storage.clear()
})

after(async () => {
  await server?.close()
  if (originalStorage) Object.defineProperty(globalThis, 'localStorage', originalStorage)
  else delete globalThis.localStorage
})

function login(id) {
  useAuthStore.getState().login({ id, name: `Customer ${id}`, roles: ['customer'] }, `token-${id}`)
}

function deferred() {
  let resolve
  let reject
  const promise = new Promise((res, rej) => { resolve = res; reject = rej })
  return { promise, resolve, reject }
}

function ok(config, data) {
  return { config, data, status: 200, statusText: 'OK', headers: {} }
}

function unauthorized(config) {
  return new axios.AxiosError('Unauthenticated', 'ERR_BAD_REQUEST', config, null, {
    config, status: 401, statusText: 'Unauthorized', headers: {}, data: {},
  })
}

test('401 clears private queries and mutation results before another customer signs in', async () => {
  login(1)
  queryClient.setQueryData(['devices', {}], [{ owner: 1, serial: 'private-serial' }])
  queryClient.setQueryData(['user'], { id: 1 })
  queryClient.setQueryData(['notifications', {}], [{ message: 'private-message' }])
  await queryClient.getMutationCache().build(queryClient, {
    mutationFn: async () => ({ private: 'mutation-result' }),
  }).execute()
  apiClient.defaults.adapter = async (config) => { throw unauthorized(config) }

  await assert.rejects(apiClient.get('/notifications'), (error) => error.response?.status === 401)
  assert.equal(useAuthStore.getState().isAuthenticated, false)
  assert.equal(localStorage.getItem('auth_token'), null)
  assert.equal(JSON.parse(localStorage.getItem('auth-storage')).state.user, null)
  assert.equal(queryClient.getQueryCache().getAll().length, 0)
  assert.equal(queryClient.getMutationCache().getAll().length, 0)

  login(2)
  let fetches = 0
  const devices = await queryClient.fetchQuery({
    queryKey: ['devices', {}],
    queryFn: async () => { fetches++; return [{ owner: 2 }] },
  })
  assert.deepEqual(devices, [{ owner: 2 }])
  assert.equal(fetches, 1)
})

test('logout cancels pending private queries and late results cannot refill the cache', async () => {
  login(1)
  const started = deferred()
  const response = deferred()
  let signal
  const pending = queryClient.fetchQuery({
    queryKey: ['devices', {}],
    queryFn: (context) => {
      signal = context.signal
      started.resolve()
      return response.promise
    },
  })
  const rejected = assert.rejects(pending)
  await started.promise
  useAuthStore.getState().logout()
  assert.equal(signal.aborted, true)
  login(2)
  response.resolve([{ owner: 1 }])
  await rejected
  assert.equal(queryClient.getQueryData(['devices', {}]), undefined)
})

test('switching accounts clears fresh private queries even without an explicit logout', () => {
  login(1)
  queryClient.setQueryData(['devices', {}], [{ owner: 1 }])
  login(2)
  assert.equal(queryClient.getQueryData(['devices', {}]), undefined)
})

test('public queries remain available and finish loading across session expiry', async () => {
  login(1)
  const response = deferred()
  const started = deferred()
  const pending = queryClient.fetchQuery({
    queryKey: ['public-branches'],
    meta: { public: true },
    queryFn: () => { started.resolve(); return response.promise },
  })
  await started.promise
  useAuthStore.getState().logout()
  response.resolve([{ name: 'Public branch' }])
  assert.deepEqual(await pending, [{ name: 'Public branch' }])
  assert.deepEqual(queryClient.getQueryData(['public-branches']), [{ name: 'Public branch' }])
  login(2)
  assert.deepEqual(queryClient.getQueryData(['public-branches']), [{ name: 'Public branch' }])
})

test('a late success from the previous session cannot run a mutation success callback', async () => {
  login(1)
  const started = deferred()
  const response = deferred()
  let requestConfig
  let successes = 0
  apiClient.defaults.adapter = async (config) => {
    requestConfig = config
    started.resolve()
    return response.promise
  }
  const mutation = queryClient.getMutationCache().build(queryClient, {
    mutationFn: () => apiClient.get('/user'),
    onSuccess: () => {
      successes++
      queryClient.setQueryData(['user'], { id: 1 })
    },
    onError: () => {},
  })
  const rejected = assert.rejects(mutation.execute(), axios.isCancel)
  await started.promise
  useAuthStore.getState().logout()
  login(2)
  response.resolve(ok(requestConfig, { id: 1 }))
  await rejected
  assert.equal(successes, 0)
  assert.equal(queryClient.getQueryData(['user']), undefined)
  assert.equal(useAuthStore.getState().user.id, 2)
})

test('a late 401 from the previous session cannot log out the next account', async () => {
  login(1)
  const started = deferred()
  const response = deferred()
  let requestConfig
  apiClient.defaults.adapter = async (config) => {
    requestConfig = config
    started.resolve()
    return response.promise
  }
  const rejected = assert.rejects(apiClient.get('/notifications'), axios.isCancel)
  await started.promise
  useAuthStore.getState().logout()
  login(2)
  queryClient.setQueryData(['devices', {}], [{ owner: 2 }])
  response.reject(unauthorized(requestConfig))
  await rejected
  assert.equal(useAuthStore.getState().user.id, 2)
  assert.equal(useAuthStore.getState().isAuthenticated, true)
  assert.deepEqual(queryClient.getQueryData(['devices', {}]), [{ owner: 2 }])
})
