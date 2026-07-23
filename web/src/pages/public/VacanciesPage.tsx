import { useQuery } from '@tanstack/react-query'
import { ArrowRight, ArrowUpRight, Check, Mail, Sparkles } from 'lucide-react'
import { getPublicBranches } from '@/api/public'
import { openRoles } from './content'

export default function VacanciesPage() {
  const { data: branches = [] } = useQuery({
    queryKey: ['public-branches'],
    queryFn: getPublicBranches,
  })
  const applicationEmail = branches.find((branch) => branch.email)?.email
  const applicationHref = applicationEmail
    ? `mailto:${applicationEmail}?subject=${encodeURIComponent('Application to join Mufasah Electronics')}`
    : '#vacancies'

  return (
    <>
      <section className="marketing-careers-hero">
        <div className="marketing-container marketing-careers-grid">
          <div>
            <p className="marketing-eyebrow marketing-eyebrow-light"><Sparkles size={16} /> Careers at Mufasah</p>
            <h1>Do work that makes technology feel more human.</h1>
            <p>Join a curious, customer-focused team helping people choose, use, and protect the devices that power everyday life.</p>
            <a href="#vacancies" className="marketing-button marketing-button-light">See open roles <ArrowRight size={18} /></a>
          </div>
          <div className="marketing-careers-art" aria-hidden="true">
            <div className="marketing-orbit marketing-orbit-one"><span>Listen</span></div>
            <div className="marketing-orbit marketing-orbit-two"><span>Learn</span></div>
            <div className="marketing-orbit marketing-orbit-three"><span>Grow</span></div>
            <div className="marketing-careers-logo"><img src="/logo_transparent.png" alt="" /></div>
          </div>
        </div>
      </section>

      <section className="marketing-section marketing-career-benefits">
        <div className="marketing-container">
          <div className="marketing-section-heading">
            <div><p className="marketing-kicker">Life at Mufasah</p><h2>Bring your perspective. Build your craft.</h2></div>
            <p>We value people who are thoughtful, practical, and genuinely interested in helping others.</p>
          </div>
          <div className="marketing-benefit-grid">
            {[
              ['Keep learning', 'Build product knowledge and customer-service skills through everyday experience.'],
              ['Make a visible impact', 'See how your work improves the experience of customers in your own community.'],
              ['Grow together', 'Work with teammates who share ideas, take ownership, and support one another.'],
            ].map(([title, copy], index) => (
              <article key={title}><span>0{index + 1}</span><h3>{title}</h3><p>{copy}</p></article>
            ))}
          </div>
        </div>
      </section>

      <section id="vacancies" className="marketing-section marketing-vacancies-section">
        <div className="marketing-container">
          <div className="marketing-vacancies-heading">
            <div><p className="marketing-kicker">Current opportunities</p><h2>Find your place at Mufasah.</h2></div>
            <p>{openRoles.length} open roles</p>
          </div>
          <div className="marketing-role-list">
            {openRoles.map(({ title, location, type, description, icon: Icon }) => (
              <article key={title}>
                <div className="marketing-role-icon"><Icon /></div>
                <div className="marketing-role-main">
                  <div><h3>{title}</h3><p>{description}</p></div>
                  <div className="marketing-role-meta"><span>{location}</span><span>{type}</span></div>
                </div>
                <a href={`${applicationHref}${applicationEmail ? `&body=${encodeURIComponent(`Hello Mufasah team,\n\nI would like to apply for the ${title} role.`)}` : ''}`} aria-label={`Apply for ${title}`}>
                  Apply <ArrowUpRight />
                </a>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section className="marketing-section">
        <div className="marketing-container">
          <div className="marketing-application-panel">
            <div>
              <p className="marketing-kicker">How to apply</p>
              <h2>A simple introduction is a good place to start.</h2>
              <p>Send us your CV and tell us which role interests you, what you enjoy about helping customers, and what you hope to learn next.</p>
              <div className="marketing-application-checks">
                <span><Check /> Include your CV</span>
                <span><Check /> Name the role</span>
                <span><Check /> Tell us about yourself</span>
              </div>
            </div>
            <a href={applicationHref} className="marketing-application-email">
              <span><Mail /></span>
              <div><small>Send your application</small><strong>{applicationEmail || 'Contact your nearest branch'}</strong></div>
              <ArrowUpRight />
            </a>
          </div>
        </div>
      </section>
    </>
  )
}
