import { useQuery } from '@tanstack/react-query'
import { ArrowRight, ArrowUpRight, Check, MapPin, ShieldCheck, Sparkles } from 'lucide-react'
import { Link } from 'react-router-dom'
import { getPublicBranches } from '@/api/public'
import { productHighlights, values } from './content'

export default function HomePage() {
  const { data: branches = [], isLoading } = useQuery({
    queryKey: ['public-branches'],
    queryFn: getPublicBranches,
  })

  return (
    <>
      <section className="marketing-hero">
        <div className="marketing-container marketing-hero-grid">
          <div className="marketing-hero-copy">
            <p className="marketing-eyebrow"><Sparkles size={16} /> Technology, thoughtfully selected</p>
            <h1>Electronics that fit the way you live.</h1>
            <p className="marketing-lead">
              Discover dependable devices, expert local advice, and support that continues long after you leave the store.
            </p>
            <div className="marketing-actions">
              <a href="#products" className="marketing-button marketing-button-dark">
                Explore our products <ArrowRight size={18} />
              </a>
              <a href="#contact" className="marketing-button marketing-button-text">
                Find a branch <ArrowUpRight size={17} />
              </a>
            </div>
            <div className="marketing-proof">
              <div><Check size={15} /> Curated quality</div>
              <div><Check size={15} /> Local support</div>
              <div><Check size={15} /> Device protection</div>
            </div>
          </div>
          <div className="marketing-hero-visual">
            <img src="/images/mufasah-electronics-hero.png" alt="A curated collection of modern electronics" />
            <div className="marketing-floating-card">
              <span><ShieldCheck size={19} /></span>
              <div><small>Beyond the purchase</small><strong>Protection that stays with you</strong></div>
            </div>
          </div>
        </div>
      </section>

      <section className="marketing-marquee" aria-label="Mufasah service promise">
        <div>
          <span>Smartphones</span><i />
          <span>Computing</span><i />
          <span>Audio</span><i />
          <span>Wearables</span><i />
          <span>Accessories</span><i />
          <span>Device protection</span>
        </div>
      </section>

      <section id="products" className="marketing-section">
        <div className="marketing-container">
          <div className="marketing-section-heading">
            <div>
              <p className="marketing-kicker">Explore the collection</p>
              <h2>Technology for every part of your day.</h2>
            </div>
            <p>Curated categories, clear advice, and products selected for real everyday use.</p>
          </div>
          <div className="marketing-product-grid">
            {productHighlights.map(({ name, eyebrow, description, icon: Icon, tone }, index) => (
              <article key={name} className={`marketing-product-card marketing-product-${tone} ${index < 2 ? 'marketing-product-wide' : ''}`}>
                <div>
                  <p>{eyebrow}</p>
                  <h3>{name}</h3>
                  <span>{description}</span>
                </div>
                <div className="marketing-product-icon"><Icon /></div>
                <a href="#contact" aria-label={`Enquire about ${name}`}>Enquire <ArrowUpRight size={17} /></a>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section className="marketing-section marketing-values-section">
        <div className="marketing-container marketing-values-layout">
          <div className="marketing-values-intro">
            <p className="marketing-kicker">The Mufasah difference</p>
            <h2>The right technology should feel simple.</h2>
            <p>Our job is to make every step—from choosing to setting up—clear, useful, and human.</p>
            <Link to="/about" className="marketing-inline-link">Meet Mufasah <ArrowRight size={17} /></Link>
          </div>
          <div className="marketing-values-list">
            {values.map((value, index) => (
              <article key={value.title}>
                <span>0{index + 1}</span>
                <div><h3>{value.title}</h3><p>{value.copy}</p></div>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section id="contact" className="marketing-section marketing-contact-section">
        <div className="marketing-container">
          <div className="marketing-contact-panel">
            <div className="marketing-contact-copy">
              <p className="marketing-kicker">Closer than you think</p>
              <h2>Let’s find the right device together.</h2>
              <p>Call or email a Mufasah branch for product advice, availability, and support.</p>
            </div>
            <div className="marketing-branch-list">
              {isLoading && <div className="marketing-branch-loading">Finding your nearest Mufasah branch…</div>}
              {!isLoading && branches.length === 0 && (
                <div className="marketing-branch-loading">Branch contact details will be available here soon.</div>
              )}
              {branches.map((branch) => (
                <article key={branch.id}>
                  <div className="marketing-branch-pin"><MapPin /></div>
                  <div>
                    <h3>{branch.name}</h3>
                    <p>{[branch.address, branch.city].filter(Boolean).join(', ')}</p>
                    <div>
                      {branch.phone && <a href={`tel:${branch.phone}`}>{branch.phone}</a>}
                      {branch.email && <a href={`mailto:${branch.email}`}>{branch.email}</a>}
                    </div>
                  </div>
                  {branch.phone && <a className="marketing-branch-action" href={`tel:${branch.phone}`} aria-label={`Call ${branch.name}`}><ArrowUpRight /></a>}
                </article>
              ))}
            </div>
          </div>
        </div>
      </section>

      <section className="marketing-cta-strip">
        <div className="marketing-container">
          <div><p>Already part of Mufasah?</p><h2>Your devices, protected and connected.</h2></div>
          <Link to="/login" className="marketing-button marketing-button-light">Open customer portal <ArrowRight size={18} /></Link>
        </div>
      </section>
    </>
  )
}
