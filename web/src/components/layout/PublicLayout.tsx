import { useEffect, useRef, useState } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { ArrowUpRight, Mail, Menu, Phone, X } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { getPublicBranches } from '@/api/public'

const links = [
  { to: '/', label: 'Home' },
  { to: '/#products', label: 'Products' },
  { to: '/about', label: 'About us' },
  { to: '/vacancies', label: 'Vacancies' },
]

const routeMetadata: Record<string, { title: string; description: string }> = {
  '/': {
    title: 'Mufasah Electronics — Home entertainment, thoughtfully selected',
    description: 'Discover televisions, home-theatre systems, Bluetooth speakers, soundbars, and expert local advice from Mufasah Electronics.',
  },
  '/about': {
    title: 'About Mufasah Electronics — Local advice, genuine care',
    description: 'Meet the local technology partner making home entertainment easier to choose, enjoy, and protect.',
  },
  '/vacancies': {
    title: 'Careers at Mufasah Electronics',
    description: 'Explore opportunities to join the customer-focused team at Mufasah Electronics.',
  },
}

export default function PublicLayout() {
  const [menuOpen, setMenuOpen] = useState(false)
  const [headerScrolled, setHeaderScrolled] = useState(false)
  const headerRef = useRef<HTMLElement>(null)
  const location = useLocation()
  const { data: branches = [] } = useQuery({
    queryKey: ['public-branches'],
    queryFn: getPublicBranches,
  })
  const contactBranch = branches.find((branch) => branch.phone || branch.email)

  useEffect(() => {
    setMenuOpen(false)
    const metadata = routeMetadata[location.pathname] ?? routeMetadata['/']
    document.title = metadata.title
    document.querySelector('meta[name="description"]')?.setAttribute('content', metadata.description)
    document.querySelector('meta[property="og:title"]')?.setAttribute('content', metadata.title)
    document.querySelector('meta[property="og:description"]')?.setAttribute('content', metadata.description)

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    const scrollTimer = window.setTimeout(() => {
      if (location.hash) {
        document.getElementById(location.hash.slice(1))?.scrollIntoView({
          behavior: prefersReducedMotion ? 'auto' : 'smooth',
          block: 'start',
        })
      } else {
        window.scrollTo({ top: 0, behavior: 'auto' })
      }
    }, 0)

    return () => window.clearTimeout(scrollTimer)
  }, [location.pathname, location.hash])

  useEffect(() => {
    if (!menuOpen) return

    const previousOverflow = document.body.style.overflow
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setMenuOpen(false)
    }

    document.body.style.overflow = 'hidden'
    document.addEventListener('keydown', closeOnEscape)

    return () => {
      document.body.style.overflow = previousOverflow
      document.removeEventListener('keydown', closeOnEscape)
    }
  }, [menuOpen])

  useEffect(() => {
    let frame = 0

    const updateHeader = () => {
      frame = 0
      const scrollTop = window.scrollY
      const scrollableHeight = document.documentElement.scrollHeight - window.innerHeight
      const progress = scrollableHeight > 0 ? Math.min(scrollTop / scrollableHeight, 1) : 0

      setHeaderScrolled(scrollTop > 18)
      headerRef.current?.style.setProperty('--marketing-scroll-progress', `${progress * 100}%`)
    }

    const handleScroll = () => {
      if (!frame) frame = window.requestAnimationFrame(updateHeader)
    }

    updateHeader()
    window.addEventListener('scroll', handleScroll, { passive: true })
    window.addEventListener('resize', handleScroll)

    return () => {
      window.removeEventListener('scroll', handleScroll)
      window.removeEventListener('resize', handleScroll)
      if (frame) window.cancelAnimationFrame(frame)
    }
  }, [])

  const handleAnchorClick = (to: string) => {
    if (!to.includes('#')) return
    const [, id] = to.split('#')
    window.setTimeout(() => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' }), 0)
  }

  return (
    <div className="marketing-site">
      <header
        ref={headerRef}
        className={`marketing-header${headerScrolled ? ' marketing-header-scrolled' : ''}`}
      >
        <div className="marketing-container marketing-nav">
          <Link to="/" className="marketing-brand" aria-label="Mufasah Electronics home">
            <span className="marketing-brand-mark">
              <img src="/logo_transparent.png" alt="" />
            </span>
            <span>
              <strong>Mufasah</strong>
              <small>Electronics</small>
            </span>
          </Link>

          <nav className="marketing-nav-links" aria-label="Main navigation">
            {links.map((link) => (
              link.to.includes('#') ? (
                <Link
                  key={link.to}
                  to={link.to}
                  className={location.pathname === '/' && location.hash === '#products' ? 'active' : undefined}
                  onClick={() => handleAnchorClick(link.to)}
                >
                  {link.label}
                </Link>
              ) : (
                <NavLink key={link.to} to={link.to} end={link.to === '/'}>
                  {link.label}
                </NavLink>
              )
            ))}
          </nav>

          <div className="marketing-nav-actions">
            <Link to="/login" className="marketing-login">Portal login</Link>
            <a
              href={contactBranch?.phone ? `tel:${contactBranch.phone}` : '#contact'}
              className="marketing-button marketing-button-small marketing-button-dark"
            >
              Talk to us <ArrowUpRight size={15} />
            </a>
          </div>

          <button
            className="marketing-menu-button"
            aria-label={menuOpen ? 'Close menu' : 'Open menu'}
            aria-expanded={menuOpen}
            onClick={() => setMenuOpen((open) => !open)}
          >
            {menuOpen ? <X /> : <Menu />}
          </button>
        </div>

        {menuOpen && (
          <nav className="marketing-mobile-menu" aria-label="Mobile navigation">
            {links.map((link) => (
              <Link key={link.to} to={link.to} onClick={() => handleAnchorClick(link.to)}>
                {link.label}
              </Link>
            ))}
            <Link to="/login">Portal login</Link>
            <a
              href={contactBranch?.phone ? `tel:${contactBranch.phone}` : '/#contact'}
              className="marketing-mobile-contact"
            >
              Talk to a branch <ArrowUpRight size={16} />
            </a>
          </nav>
        )}
        <span className="marketing-scroll-progress" aria-hidden="true" />
      </header>

      <main>
        <Outlet />
      </main>

      <footer className="marketing-footer">
        <div className="marketing-container">
          <div className="marketing-footer-top">
            <div>
              <Link to="/" className="marketing-brand marketing-brand-footer">
                <span className="marketing-brand-mark">
                  <img src="/logo_transparent.png" alt="" />
                </span>
                <span><strong>Mufasah</strong><small>Electronics</small></span>
              </Link>
              <p>Useful technology. Thoughtful service.<br />Support that stays close.</p>
            </div>
            <div className="marketing-footer-links">
              <div>
                <span>Explore</span>
                <Link to="/">Home</Link>
                <Link to="/#products" onClick={() => handleAnchorClick('/#products')}>Products</Link>
                <Link to="/about">About us</Link>
                <Link to="/vacancies">Vacancies</Link>
              </div>
              <div>
                <span>Connect</span>
                {contactBranch?.phone && <a href={`tel:${contactBranch.phone}`}><Phone size={15} /> {contactBranch.phone}</a>}
                {contactBranch?.email && <a href={`mailto:${contactBranch.email}`}><Mail size={15} /> {contactBranch.email}</a>}
                <Link to="/login">Customer portal</Link>
              </div>
            </div>
          </div>
          <div className="marketing-footer-bottom">
            <span>© {new Date().getFullYear()} Mufasah Electronics.</span>
            <span>Built around your everyday technology.</span>
          </div>
        </div>
      </footer>
    </div>
  )
}
