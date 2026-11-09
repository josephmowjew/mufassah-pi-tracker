import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'

const reloadAttemptKey = `vite:preload-reload:${import.meta.url}`

window.addEventListener('vite:preloadError', () => {
  if (!navigator.onLine) return

  // Reload once per tab and entry bundle. A persistent failure reaches the error boundary.
  try {
    if (sessionStorage.getItem(reloadAttemptKey)) return
    sessionStorage.setItem(reloadAttemptKey, '1')
  } catch {
    // Without session storage, the reload cannot be guarded across page loads.
    return
  }

  window.location.reload()
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
