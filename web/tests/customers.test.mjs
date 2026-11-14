import assert from 'node:assert/strict'
import { before, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'

let server
let customers

before(async () => {
  server = await createServer({
    root: fileURLToPath(new URL('../', import.meta.url)), configFile: false,
    resolve: { alias: { '@': fileURLToPath(new URL('../src', import.meta.url)) } },
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    appType: 'custom', optimizeDeps: { noDiscovery: true },
  })
  customers = await server.ssrLoadModule('/src/api/customers.ts')
})

test.after(() => server.close())

test('the endpoint follows the actor, not the page', () => {
  assert.equal(customers.customerCreateMode(['customer_service']), 'customer_service')
  assert.equal(customers.customerCreateMode(['store_admin']), 'admin')
  assert.equal(customers.customerCreateMode(['super_admin', 'customer_service']), 'customer_service')
  assert.equal(customers.customerCreateMode([]), 'admin')
})

test('each mode creates through its own route', () => {
  assert.equal(customers.customerCreatePath('admin'), '/admin/users')
  assert.equal(customers.customerCreatePath('customer_service'), '/customer-service/customers')
})

test('only the admin search is narrowed to customers', () => {
  const admin = new URL(customers.customerSearchPath('admin', '  Chisomo  '), 'https://example.test')
  assert.equal(admin.searchParams.get('type'), 'customer', 'staff must not appear in a customer search')
  assert.equal(admin.searchParams.get('search'), 'Chisomo')

  const desk = new URL(customers.customerSearchPath('customer_service', 'Chisomo'), 'https://example.test')
  assert.equal(desk.searchParams.get('type'), null, 'the customer-service list is already role scoped')
  assert.equal(desk.searchParams.get('per_page'), '10')
})

test('the admin payload carries the customer role and drops blank contact fields', () => {
  const payload = customers.buildCreatePayload(
    { name: '  Chisomo Banda ', phone_number: ' 0991234567 ', email: '', national_id: null },
    'admin'
  )

  assert.deepEqual(payload, {
    name: 'Chisomo Banda',
    phone_number: '0991234567',
    email: null,
    national_id: null,
    role: 'customer',
  })
})

test('the customer-service payload never sets a role or password', () => {
  const payload = customers.buildCreatePayload({ name: 'Walk-in', phone_number: '0991' }, 'customer_service')

  assert.equal('role' in payload, false)
  assert.equal('password' in payload, false)
  assert.equal('password_confirmation' in payload, false)
  assert.equal(payload.phone_number, '0991')
})

test('an explicit password is confirmed in the same payload', () => {
  const payload = customers.buildCreatePayload({ name: 'Walk-in', password: 'secret-value' }, 'admin')

  assert.equal(payload.password, 'secret-value')
  assert.equal(payload.password_confirmation, 'secret-value')
})
