import assert from 'node:assert/strict'
import { before, after, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'

let server
let dates
const originalTimezone = process.env.TZ

before(async () => {
  server = await createServer({
    root: fileURLToPath(new URL('../', import.meta.url)), configFile: false,
    resolve: { alias: { '@': fileURLToPath(new URL('../src', import.meta.url)) } },
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    appType: 'custom', optimizeDeps: { noDiscovery: true },
  })
  dates = await server.ssrLoadModule('/src/lib/datetime.ts')
})

after(async () => {
  if (originalTimezone === undefined) delete process.env.TZ
  else process.env.TZ = originalTimezone
  await server?.close()
})

for (const timezone of ['UTC', 'America/New_York', 'Asia/Tokyo', 'Africa/Blantyre']) {
  test(`displays Malawi time and calendar dates in a ${timezone} browser`, () => {
    process.env.TZ = timezone
    assert.equal(dates.formatMalawiTime('2026-01-15T10:00:00Z', 'en-GB'), '12:00:00')
    assert.equal(dates.formatMalawiTime('2026-01-15T12:00:00+02:00', 'en-GB'), '12:00:00')
    assert.equal(dates.formatMalawiDate('2026-01-15T22:30:00Z', 'en-GB'), '16/01/2026')
    assert.equal(dates.formatMalawiDateTime('2026-01-15T22:30:00Z', 'en-GB'), '16/01/2026, 00:30:00')
    assert.equal(dates.formatMalawiDate('2026-01-15', 'en-GB'), '15/01/2026')
    assert.equal(dates.malawiDateInput('2025-12-31T22:30:00Z'), '2026-01-01')
    const calendar = dates.malawiCalendarDate('2025-12-31T22:30:00Z')
    assert.deepEqual([calendar.getFullYear(), calendar.getMonth(), calendar.getDate()], [2026, 0, 1])
    assert.equal(dates.malawiHour('2025-12-31T22:30:00Z'), 0)
  })
}

test('handles legacy UTC datetime strings and does not mutate Date inputs', () => {
  process.env.TZ = 'America/New_York'
  assert.equal(dates.formatMalawiTime('2026-01-15 10:00:00', 'en-GB'), '12:00:00')
  assert.equal(dates.formatMalawiTime('2026-01-15T10:00:00', 'en-GB'), '12:00:00')
  const input = new Date('2026-01-15T22:30:00Z')
  dates.formatMalawiDateTime(input)
  assert.equal(input.toISOString(), '2026-01-15T22:30:00.000Z')
})

test('uses Malawi for date and time options even when a different timezone is supplied', () => {
  assert.equal(dates.formatMalawiTime('2026-01-15T10:00:00Z', 'en-GB', { timeZone: 'UTC' }), '12:00:00')
  assert.equal(dates.formatMalawiDate('2026-01-15T22:30:00Z', 'en-GB', {
    year: 'numeric', month: 'long', day: 'numeric',
  }), '16 January 2026')
})


test('date input defaults and presets use the Malawi day before UTC midnight', (t) => {
  process.env.TZ = 'America/New_York'
  t.mock.timers.enable({ apis: ['Date'], now: new Date('2025-12-31T22:30:00Z').getTime() })
  assert.equal(dates.malawiDateInput(), '2026-01-01')
  assert.equal(dates.malawiHour(), 0)
  const today = dates.malawiCalendarDate()
  assert.deepEqual([today.getFullYear(), today.getMonth(), today.getDate()], [2026, 0, 1])
})


test('dashboard today alerts use Malawi midnight instead of the browser day', async (t) => {
  process.env.TZ = 'America/New_York'
  t.mock.timers.enable({ apis: ['Date'], now: new Date('2026-01-14T22:30:00Z').getTime() })
  const storage = Object.getOwnPropertyDescriptor(globalThis, 'localStorage')
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true, value: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
  })
  t.after(() => {
    if (storage) Object.defineProperty(globalThis, 'localStorage', storage)
    else delete globalThis.localStorage
  })
  const { apiClient } = await server.ssrLoadModule('/src/api/client.ts')
  const { dashboardApi } = await server.ssrLoadModule('/src/api/dashboard.ts')
  const get = apiClient.get
  t.after(() => { apiClient.get = get })
  apiClient.get = async (path) => {
    if (path.startsWith('/devices')) return { data: { data: [], meta: { total: 0 } } }
    if (path.startsWith('/geofences')) return { data: { data: [] } }
    return { data: { data: { notifications: [
      { created_at: '2026-01-14T21:59:59Z' },
      { created_at: '2026-01-14T22:00:00Z' },
      { created_at: '2026-01-15T23:59:59+02:00' },
      { created_at: '2026-01-15T22:00:00Z' },
    ] } } }
  }
  assert.equal((await dashboardApi.getStats()).alertsToday, 2)
})


test('staff report ISO timestamps display once across the Malawi midnight boundary', () => {
  process.env.TZ = 'America/New_York'
  const shift = {
    started_at: '2026-01-15T00:30:00+02:00', ended_at: '2026-01-15T12:00:00+02:00',
  }
  assert.equal(dates.formatMalawiDateTime(shift.started_at, 'en-GB'), '15/01/2026, 00:30:00')
  assert.equal(dates.formatMalawiDateTime(shift.ended_at, 'en-GB'), '15/01/2026, 12:00:00')
})
