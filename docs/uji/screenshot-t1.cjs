// Ambil screenshot dashboard pemilik & penghuni setelah login sungguhan.
const CDP_BASE = 'http://127.0.0.1:9222'
const APP = 'http://localhost:3000'
const fs = require('node:fs')

async function main() {
  const tab = await (await fetch(`${CDP_BASE}/json/new?about:blank`, { method: 'PUT' })).json()
  const ws = new WebSocket(tab.webSocketDebuggerUrl)
  await new Promise((r) => (ws.onopen = r))

  let id = 0
  const pending = new Map()
  ws.onmessage = (ev) => {
    const m = JSON.parse(ev.data)
    if (m.id && pending.has(m.id)) {
      pending.get(m.id)(m.result ?? {})
      pending.delete(m.id)
    }
  }
  const kirim = (method, params = {}) =>
    new Promise((res) => {
      const n = ++id
      pending.set(n, res)
      ws.send(JSON.stringify({ id: n, method, params }))
    })

  await kirim('Page.enable')
  await kirim('Runtime.enable')

  async function tangkap(email, jalur, berkas) {
    await kirim('Page.navigate', { url: `${APP}/masuk` })
    await new Promise((r) => setTimeout(r, 1500))
    await kirim('Runtime.evaluate', {
      expression: `
        (() => {
          const set = (el, v) => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })) }
          set(document.querySelector('#email'), '${email}')
          set(document.querySelector('#password'), 'password')
          document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
          return true
        })()
      `,
      awaitPromise: true,
    })
    await new Promise((r) => setTimeout(r, 2000))
    await kirim('Page.navigate', { url: `${APP}${jalur}` })
    await new Promise((r) => setTimeout(r, 1800))

    const hasil = await kirim('Page.captureScreenshot', { format: 'png' })
    fs.writeFileSync(berkas, Buffer.from(hasil.data, 'base64'))
    console.log(`${berkas} (${(fs.statSync(berkas).size / 1024).toFixed(1)} KB)`)
  }

  await tangkap('pemilik@kos.test', '/pemilik', 'docs/uji/T1-dashboard-pemilik.png')
  await tangkap('penghuni@kos.test', '/penghuni', 'docs/uji/T1-dashboard-penghuni.png')

  ws.close()
}

main().catch((e) => {
  console.error('gagal:', e.message)
  process.exitCode = 1
})
