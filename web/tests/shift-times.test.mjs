import assert from 'node:assert/strict'
import { before, after, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { createServer } from 'vite'

let server
let ShiftTimes
const originalTimezone = process.env.TZ
before(async () => {
  server = await createServer({
    root: fileURLToPath(new URL('../', import.meta.url)), configFile: false,
    resolve: { alias: { '@': fileURLToPath(new URL('../src', import.meta.url)) } },
    esbuild: { jsx: 'automatic' },
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    appType: 'custom', optimizeDeps: { noDiscovery: true },
  })
  ;({ ShiftTimes } = await server.ssrLoadModule('/src/components/shifts/ShiftTimes.tsx'))
})
after(async () => {
  if (originalTimezone === undefined) delete process.env.TZ
  else process.env.TZ = originalTimezone
  await server?.close()
})
const render = (props) => renderToStaticMarkup(createElement(ShiftTimes, props))

for (const timezone of ['UTC', 'America/New_York', 'Africa/Blantyre']) {
  test(`labels both endpoints with readable Malawi dates and am/pm in ${timezone}`, () => {
    process.env.TZ = timezone
    const html = render({ startedAt: '2026-10-31T06:31:00Z', endedAt: '2026-10-31T14:55:00Z', status: 'completed' })
    assert.match(html, /Started<\/dt>/)
    assert.match(html, /Ended<\/dt>/)
    assert.match(html, /31 Oct 2026, 8:31 am/)
    assert.match(html, /31 Oct 2026, 4:55 pm/)
  })
}
test('overnight shifts retain the distinct end date and readable midnight/noon', () => {
  const html = render({ startedAt: '2026-10-31T22:00:00Z', endedAt: '2026-11-02T10:00:00Z', status: 'completed' })
  assert.match(html, /1 Nov 2026, 12:00 am/)
  assert.match(html, /2 Nov 2026, 12:00 pm/)
})
test('only active shifts say still working when no end time is recorded', () => {
  const props = { startedAt: '2026-10-31T06:31:00Z', endedAt: null }
  assert.match(render({ ...props, status: 'active' }), /Still working/)
  for (const status of ['completed', 'cancelled']) {
    const html = render({ ...props, status })
    assert.match(html, /Not recorded/)
    assert.doesNotMatch(html, /Still working/)
  }
})
