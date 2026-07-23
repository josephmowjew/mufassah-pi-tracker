import { ArrowRight, HeartHandshake, ShieldCheck, Sparkles, Users } from 'lucide-react'
import { Link } from 'react-router-dom'
import { values } from './content'

export default function AboutPage() {
  return (
    <>
      <section className="marketing-page-hero">
        <div className="marketing-container marketing-page-hero-grid">
          <div>
            <p className="marketing-eyebrow"><Sparkles size={16} /> Our story</p>
            <h1>Technology should bring people closer—not make life more complicated.</h1>
          </div>
          <div className="marketing-page-hero-side">
            <p>Mufasah Electronics is a local technology partner built around one simple idea: help people choose well, feel supported, and protect the devices they depend on.</p>
            <a href="#our-story" className="marketing-inline-link">Discover our story <ArrowRight size={17} /></a>
          </div>
        </div>
      </section>

      <section className="marketing-about-visual">
        <div className="marketing-container">
          <div className="marketing-about-image">
            <img src="/images/mufasah-electronics-hero.png" alt="Modern electronics selected by Mufasah" />
            <div className="marketing-about-badge">
              <span>Local insight</span>
              <strong>Genuine care</strong>
            </div>
          </div>
        </div>
      </section>

      <section id="our-story" className="marketing-section">
        <div className="marketing-container marketing-story-layout">
          <p className="marketing-kicker">Why we exist</p>
          <div>
            <h2>More confidence in every choice.</h2>
            <div className="marketing-story-columns">
              <p>Technology has become part of nearly everything we do. Yet choosing the right device can still feel difficult, and getting help after a purchase can be even harder.</p>
              <p>Mufasah was created to change that experience. We combine thoughtfully selected electronics with clear guidance, friendly local service, and device-protection expertise.</p>
            </div>
          </div>
        </div>
      </section>

      <section className="marketing-section marketing-mission-section">
        <div className="marketing-container">
          <div className="marketing-mission-grid">
            <article className="marketing-mission-feature">
              <p className="marketing-kicker">Our mission</p>
              <h2>Make useful technology easier to access, understand, and protect.</h2>
              <ShieldCheck />
            </article>
            <article><Users /><h3>People first</h3><p>We listen before we recommend, and we explain without the jargon.</p></article>
            <article><HeartHandshake /><h3>Here for the long term</h3><p>Our relationship with a customer does not end when the sale is complete.</p></article>
            <article><Sparkles /><h3>Always improving</h3><p>We keep learning, refining, and finding better ways to serve our communities.</p></article>
          </div>
        </div>
      </section>

      <section className="marketing-section">
        <div className="marketing-container">
          <div className="marketing-section-heading">
            <div><p className="marketing-kicker">What guides us</p><h2>Simple values. Practised every day.</h2></div>
          </div>
          <div className="marketing-about-values">
            {values.map((value, index) => (
              <article key={value.title}>
                <span>0{index + 1}</span>
                <h3>{value.title}</h3>
                <p>{value.copy}</p>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section className="marketing-cta-strip">
        <div className="marketing-container">
          <div><p>Come grow with us</p><h2>Good technology starts with good people.</h2></div>
          <Link to="/vacancies" className="marketing-button marketing-button-light">Explore vacancies <ArrowRight size={18} /></Link>
        </div>
      </section>
    </>
  )
}
