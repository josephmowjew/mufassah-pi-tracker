import { useQuery } from '@tanstack/react-query'
import { ArrowRight, ArrowUpRight, BriefcaseBusiness, Check, Mail } from 'lucide-react'
import { getPublicVacancies } from '@/api/public'
import { publicContact } from './content'

export default function VacanciesPage() {
  const { data: openRoles = [], isLoading: vacanciesLoading } = useQuery({
    queryKey: ['public-vacancies'],
    queryFn: getPublicVacancies,
    meta: { public: true },
  })
  const applicationEmail = publicContact.email
  const applicationHref = applicationEmail
    ? `mailto:${applicationEmail}?subject=${encodeURIComponent('Application to join Mufasah Electronics')}`
    : '#vacancies'

  return (
    <>
      <section className="marketing-careers-hero">
        <div className="marketing-container marketing-careers-grid">
          <div>
            <p className="marketing-eyebrow marketing-eyebrow-light">Careers at Mufasah</p>
            <h1>Do work that makes home entertainment feel more human.</h1>
            <p>Join a curious, customer-focused team helping people choose televisions, sound systems, and entertainment setups with confidence.</p>
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
            <p>{vacanciesLoading ? 'Checking openings' : openRoles.length > 0 ? `${openRoles.length} open ${openRoles.length === 1 ? 'role' : 'roles'}` : 'No current openings'}</p>
          </div>
          {vacanciesLoading ? (
            <div className="marketing-empty-role">
              <span><BriefcaseBusiness /></span>
              <div><h3>Checking current opportunities.</h3><p>Please give us a moment while we load the latest vacancies.</p></div>
            </div>
          ) : openRoles.length > 0 ? (
            <div className="marketing-role-list">
              {openRoles.map(({ id, title, location, employment_type, description, application_email }) => (
                <article key={id}>
                  <div className="marketing-role-icon"><BriefcaseBusiness /></div>
                  <div className="marketing-role-main">
                    <div><h3>{title}</h3><p>{description}</p></div>
                    <div className="marketing-role-meta">
                      {location && <span>{location}</span>}
                      <span>{employment_type}</span>
                    </div>
                  </div>
                  <a href={`${application_email ? `mailto:${application_email}?subject=${encodeURIComponent(`Application for ${title}`)}` : applicationHref}${(application_email || applicationEmail) ? `&body=${encodeURIComponent(`Hello Mufasah team,\n\nI would like to apply for the ${title} role.`)}` : ''}`} aria-label={`Apply for ${title}`}>
                    Apply <ArrowUpRight />
                  </a>
                </article>
              ))}
            </div>
          ) : (
            <div className="marketing-empty-role">
              <span><BriefcaseBusiness /></span>
              <div>
                <h3>No open roles right now.</h3>
                <p>We are not actively hiring today, but we are always interested in meeting thoughtful people who care about customers and great home entertainment.</p>
              </div>
              <a href={applicationHref}>Introduce yourself <ArrowUpRight /></a>
            </div>
          )}
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
