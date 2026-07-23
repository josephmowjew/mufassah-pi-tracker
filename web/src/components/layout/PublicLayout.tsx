import { useEffect, useState } from 'react'
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

export default function PublicLayout() {
  const [menuOpen, setMenuOpen] = useState(false)
  const location = useLocation()
  const { data: branches = [] } = useQuery({
    queryKey: ['public-branches'],
    queryFn: getPublicBranches,
  })
  const contactBranch = branches.find((branch) => branch.phone || branch.email)

  useEffect(() => {
    setMenuOpen(false)
    window.scrollTo({ top: 0, behavior: 'instant' })
  }, [location.pathname])

  const handleAnchorClick = (to: string) => {
    if (!to.includes('#')) return
    const [, id] = to.split('#')
    window.setTimeout(() => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' }), 0)
  }

  return (
    <div className="marketing-site">
      <header className="marketing-header">
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
                <Link key={link.to} to={link.to} onClick={() => handleAnchorClick(link.to)}>
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
          </nav>
        )}
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
                <Link to="/#products">Products</Link>
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
