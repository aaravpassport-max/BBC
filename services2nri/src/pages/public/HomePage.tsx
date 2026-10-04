/**
 * Homepage — exact match of Mn() in compiled app.js
 *
 * Sections (in DOM order):
 *   1. Hero slider (Swiper fade, autoplay 4500ms, navigation, pagination)
 *   2. Notice bar (#fff8e1)
 *   3. Services section (category tabs + service cards grid)
 *   4. Cities grid
 *   5. Stats bar (primary color bg)
 *   6. Tagline div
 *   7. Why Choose Us features grid
 *   8. Testimonials slider
 *   9. How It Works steps grid
 *  10. As Featured In
 *  11. Partners
 *  12. About section (text + image + video)
 *  13. Awards
 *  14. FAQ accordion
 *  15. Newsletter
 *  16. App download
 *  17. Location pill row
 */

import React, { useState, useEffect } from 'react'
import { resolvePrimary } from '@/lib/design-tokens'
import { Link } from 'react-router-dom'
import { Swiper, SwiperSlide } from 'swiper/react'
import { Autoplay, Pagination, Navigation, EffectFade } from 'swiper/modules'
import 'swiper/css'
import 'swiper/css/pagination'
import 'swiper/css/navigation'
import 'swiper/css/effect-fade'

import { Layout } from '@/components/layout/Layout'
import { useStore } from '@/lib/store'
import { api } from '@/lib/api'
import { parseHeroBanners, IMAGES, getAvatarImage } from '@/lib/images'
import type { Category, Service, City, Testimonial } from '@/types'

// ── Animated counter ──────────────────────────────────────────────────────────
function useCounter(target: string, duration = 1800, started: boolean) {
  const [value, setValue] = useState('0')
  useEffect(() => {
    if (!started) return
    const num = parseFloat(target.replace(/[^0-9.]/g, ''))
    const suffix = target.replace(/[0-9.,]/g, '').trim()
    if (isNaN(num)) { setValue(target); return }
    let start = 0
    const step = num / (duration / 16)
    const timer = setInterval(() => {
      start += step
      if (start >= num) { setValue(target); clearInterval(timer); return }
      setValue(Math.floor(start).toLocaleString('en-IN') + suffix)
    }, 16)
    return () => clearInterval(timer)
  }, [target, duration, started])
  return value
}

function StatCard({ number, label, primary }: { number: string; label: string; primary: string }) {
  const [ref, setRef] = useState<HTMLDivElement | null>(null)
  const [started, setStarted] = useState(false)
  const value = useCounter(number, 1800, started)

  useEffect(() => {
    if (!ref) return
    const obs = new IntersectionObserver(([entry]) => {
      if (entry.isIntersecting) { setStarted(true); obs.disconnect() }
    }, { threshold: 0.3 })
    obs.observe(ref)
    return () => obs.disconnect()
  }, [ref])

  return (
    <div ref={setRef} style={{ textAlign: 'center' }}>
      <div style={{ fontSize: 'clamp(28px, 4vw, 42px)', fontWeight: 900, color: '#ffd54f', lineHeight: 1 }}>
        {value}
      </div>
      <div style={{ fontSize: 13, color: 'rgba(255,255,255,.85)', marginTop: 4 }}>{label}</div>
    </div>
  )
}

// ── Fallback service data (matches j{} in compiled bundle) ────────────────────
const FALLBACK_SERVICES: Record<string, Service[]> = {
  property: [
    { id: 1, slug: 'complete-property-management', name: 'Property Management', short_desc: 'End-to-end property management including tenant screening, rent collection, and maintenance coordination.', img: IMAGES.property } as Service,
    { id: 2, slug: 'housekeeping-services', name: 'Housekeeping Services', short_desc: 'Professional housekeeping and cleaning services tailored for NRI properties across all major Indian cities.', img: IMAGES.housekeeping } as Service,
    { id: 3, slug: 'tenancy-management', name: 'Tenancy Management', short_desc: 'Systematic tenancy administration including tenant sourcing, screening, onboarding and rent collection.', img: IMAGES.tenancy } as Service,
    { id: 4, slug: 'rent-agreement', name: 'Rental Agreement', short_desc: 'Legally binding rent agreement drafting, stamp duty payment and sub-registrar registration in India.', img: IMAGES.rent } as Service,
  ],
  financial: [
    { id: 5, slug: 'financial-planning', name: 'Financial Investment', short_desc: 'Smart, legally compliant financial investment solutions for NRIs — mutual funds, real estate, and fixed deposits.', img: IMAGES.financial } as Service,
    { id: 6, slug: 'itr-filing', name: 'Tax Services', short_desc: 'Expert ITR filing and cross-border tax optimisation for NRIs with Indian source income.', img: IMAGES.tax } as Service,
    { id: 7, slug: 'epf-pf-withdrawal', name: 'EPF Withdrawal', short_desc: 'Complete EPF/PF withdrawal assistance for NRIs who have relocated abroad.', img: IMAGES.epf } as Service,
    { id: 8, slug: 'nre-nro-account', name: 'Financial Services', short_desc: 'Innovative NRE/NRO account opening and financial management solutions for NRI individuals.', img: IMAGES.financial2 } as Service,
  ],
  immigration: [
    { id: 9,  slug: 'single-status-certificate', name: 'Single Status Certificate', short_desc: 'Bachelorhood/Single Status Certificate for NRIs required for marriage registration abroad.', img: IMAGES.singlestatus } as Service,
    { id: 10, slug: 'birth-certificate', name: 'Birth Certificate', short_desc: 'Official birth certificate from Indian municipal authorities delivered to your overseas address.', img: IMAGES.birth } as Service,
    { id: 11, slug: 'nabc', name: 'NABC', short_desc: 'Non-Availability of Birth Certificate for NRIs when birth records are unavailable in India.', img: IMAGES.nabc } as Service,
    { id: 12, slug: 'apostille', name: 'Apostille', short_desc: 'MEA Apostille and Embassy Attestation for all Indian documents for use abroad.', img: IMAGES.apostille } as Service,
  ],
  education: [
    { id: 13, slug: 'university-transcript', name: 'University Transcripts', short_desc: 'Official sealed transcripts from any Indian university. Delivered directly to institutions or your door.', img: IMAGES.transcript } as Service,
    { id: 14, slug: 'moi', name: 'MOI Certificate', short_desc: 'Medium of Instruction certificate confirming English as the language of your Indian education.', img: IMAGES.moi } as Service,
    { id: 15, slug: 'degree-certificate', name: 'Degree Certificate', short_desc: 'Obtain original or duplicate degree certificate from any Indian university.', img: IMAGES.degree } as Service,
    { id: 16, slug: 'duplicate-marksheet', name: 'Duplicate Marksheet', short_desc: 'Duplicate marksheets from all Indian boards and universities for all levels of education.', img: IMAGES.marksheet } as Service,
  ],
}

const FALLBACK_CITIES = [
  { name: 'Pune',      slug: 'pune',      img: IMAGES.pune },
  { name: 'Mumbai',    slug: 'mumbai',    img: IMAGES.mumbai },
  { name: 'Delhi',     slug: 'delhi',     img: IMAGES.delhi },
  { name: 'Bangalore', slug: 'bangalore', img: IMAGES.bangalore },
  { name: 'Hyderabad', slug: 'hyderabad', img: IMAGES.hyderabad },
  { name: 'Chennai',   slug: 'chennai',   img: IMAGES.chennai },
  { name: 'Ahmedabad', slug: 'ahmedabad', img: IMAGES.ahmedabad },
  { name: 'Nagpur',    slug: 'nagpur',    img: IMAGES.nagpur },
]

const FALLBACK_TESTIMONIALS: Testimonial[] = [
  { id: 1, name: 'Vikram J',   location: 'Singapore',          image_url: getAvatarImage('avatar_1'), text: 'High-quality solutions in rental property management. From tenant screening to maintenance coordination, they support you at every step. Highly recommended for all NRIs.', rating: 5 },
  { id: 2, name: 'Akash R',   location: 'New York, USA',       image_url: getAvatarImage('avatar_2'), text: 'They expertly manage my Mumbai flat. Timely updates and end-to-end management made everything seamless. No more coordinating with local vendors from abroad.', rating: 5 },
  { id: 3, name: 'Anita K',   location: 'San Francisco',       image_url: getAvatarImage('avatar_3'), text: 'My Bangalore apartment was hard to monitor remotely. Dedicated team, regular inspections, and quick issue handling have made ownership completely stress-free.', rating: 5 },
  { id: 4, name: 'Rishi P',   location: 'Dubai, UAE',          image_url: undefined,                  text: 'Managing my Pune flat from abroad was challenging. Now they oversee all property needs, ensuring the unit stays well-maintained and tenant-ready at all times.', rating: 5 },
  { id: 5, name: 'Priya S',   location: 'London, UK',          image_url: getAvatarImage('avatar_5'), text: 'Used the OCI card renewal and property management services. Both were outstanding — professional, responsive, and completely hassle-free from start to finish.', rating: 5 },
]

const WHY_CHOOSE = [
  { icon: '🔒', title: 'Secure Payment Gateway',       sub: 'Assured payment gateway and user information security' },
  { icon: '📁', title: 'Document Confidentiality',      sub: 'End-to-end encryption for all documents with PII protection' },
  { icon: '✅', title: 'Vetted Service Providers',      sub: 'Verified providers delivering services of the highest quality' },
  { icon: '🕐', title: 'Round the Clock Support',       sub: '24/7 email, chat, and phone support in USA and India' },
  { icon: '👤', title: 'Dedicated Account Manager',     sub: 'End to end order management by a dedicated manager' },
  { icon: '💡', title: 'Competitive Pricing',           sub: 'Price match guarantee & market driven transparent pricing' },
  { icon: '💸', title: 'Easy Refunds',                  sub: 'Hassle-free refunds for any unused or undelivered services' },
  { icon: '📦', title: 'Order Tracking',                sub: 'Constant communication throughout the process with tracking' },
  { icon: '💻', title: 'Customer Friendly Interface',   sub: 'Simple and intuitive platform designed for NRIs of all ages' },
]

const HOW_IT_WORKS = [
  { n: 1, icon: '🔍', title: 'Search for Services',     desc: 'Browse our 44+ NRI services across 8 categories. Find exactly what you need with our smart search.' },
  { n: 2, icon: '📋', title: 'Book Your Service',       desc: 'Fill our service-specific intake form with your details. Each service has tailored fields for accuracy.' },
  { n: 3, icon: '📤', title: 'Upload Documents',        desc: 'Securely upload your documents through the encrypted portal. All files are PII-protected.' },
  { n: 4, icon: '✔️', title: 'Verification & Costing', desc: 'Our experts verify your documents and send a detailed, transparent quote within 24 hours.' },
  { n: 5, icon: '💳', title: 'Pay & Track Progress',    desc: 'Approve the quote, pay securely, and track every step in real time from your dashboard.' },
  { n: 6, icon: '🚀', title: 'Service Delivered',       desc: 'We execute and deliver results with regular status updates and a comprehensive final delivery report.' },
]

const FAQ_DATA = [
  { q: '1. What kind of services do you provide?',          a: 'We provide 44+ services across 8 categories — documentation, education, OCI/passport/visa, USCIS, property management, financial services, legal services, and taxation. All designed specifically for NRIs worldwide.' },
  { q: '2. What is your service fee structure?',           a: 'All services are quote-based. After you submit your requirements, our expert team reviews and sends a transparent, itemised quote within 24 hours. You only pay after approving the quote — no surprises.' },
  { q: '3. Are my documents secured in your system?',     a: 'Yes. All documents are stored with end-to-end encryption using AES-256. We maintain strict PII (Personally Identifiable Information) confidentiality and never share documents with third parties.' },
  { q: '4. How can you make sure documents are not misused?', a: 'We maintain strict document confidentiality protocols. Documents are accessed only by the assigned team member and are used solely for the requested service. Documents are deleted after service completion.' },
  { q: '5. What type of payment is accepted?',            a: 'We accept bank transfer (NEFT/RTGS/UPI/SWIFT), and online payment via Razorpay (credit/debit cards, UPI). International payments via SWIFT in INR — your bank converts at prevailing exchange rates.' },
]

export function HomePage() {
  const settings = useStore((s) => s.settings)
  const primary  = resolvePrimary(settings)
  const whatsapp = settings.platform_whatsapp

  // Data state
  const [activeCategory, setActiveCategory] = useState('')
  const [categories, setCategories]         = useState<Category[]>([])
  const [serviceMap, setServiceMap]         = useState<Record<string, Service[]>>({})
  const [cities, setCities]                 = useState<Array<{ name: string; slug: string; img: string }>>([])
  const [testimonials, setTestimonials]     = useState<Testimonial[]>([])
  const [openFaq, setOpenFaq]               = useState<number | null>(null)

  const banners = parseHeroBanners(settings.hero_banners)

  const stats = [
    { n: settings.stat_1_number || '10,000+', l: settings.stat_1_label || 'Happy Clients' },
    { n: settings.stat_2_number || '44+',     l: settings.stat_2_label || 'Services' },
    { n: settings.stat_3_number || '40+',     l: settings.stat_3_label || 'Locations' },
    { n: settings.stat_4_number || '23+',     l: settings.stat_4_label || 'Countries Served' },
  ]

  useEffect(() => {
    // Load categories + services
    api.get<{ categories: Category[] }>('categories?surface=homepage')
      .then((data) => {
        const cats = data.categories || []
        setCategories(cats)
        if (cats.length > 0) setActiveCategory(cats[0].slug)
        cats.forEach((cat) => {
          api.get<{ services: Service[] }>(`services?surface=homepage&category=${cat.slug}&per_page=4`)
            .then((r) => setServiceMap((prev) => ({ ...prev, [cat.slug]: r.services || [] })))
            .catch(() => {})
        })
      })
      .catch(() => {
        // Fallback categories
        setCategories([
          { id: 1, slug: 'property',   name: 'Property',   icon: '🏠', color: '#4A6FA5' },
          { id: 2, slug: 'financial',  name: 'Financial',  icon: '💰', color: '#7b1fa2' },
          { id: 3, slug: 'immigration',name: 'Immigration',icon: '✈️', color: '#c62828' },
          { id: 4, slug: 'education',  name: 'Education',  icon: '🎓', color: '#00695c' },
        ])
        setActiveCategory('property')
        ;['property', 'financial', 'immigration', 'education'].forEach((slug) => {
          api.get<{ services: Service[] }>(`services?surface=homepage&category=${slug}&per_page=4`)
            .then((r) => setServiceMap((prev) => ({ ...prev, [slug]: r.services || [] })))
            .catch(() => {})
        })
      })

    // Load cities
    api.get<{ cities: City[] }>('cities?surface=homepage')
      .then((data) => {
        const c = data.cities || []
        if (c.length > 0) {
          setCities(c.map((city) => ({
            name: city.name,
            slug: city.slug,
            img: city.image_url || IMAGES[city.slug] || IMAGES.pune,
          })))
        }
      })
      .catch(() => {})

    // Load testimonials
    api.get<{ testimonials: Testimonial[] }>('testimonials')
      .then((data) => {
        const t = (data.testimonials || []).filter((x) => x.is_active !== false)
        if (t.length > 0) setTestimonials(t)
      })
      .catch(() => {})
  }, [])

  const displayServices =
    (serviceMap[activeCategory]?.length ? serviceMap[activeCategory] : null) ??
    FALLBACK_SERVICES[activeCategory] ??
    []

  const displayCities = cities.length > 0 ? cities : FALLBACK_CITIES
  const displayTestimonials = testimonials.length > 0 ? testimonials : FALLBACK_TESTIMONIALS

  const categoryNameMap: Record<string, string> = {}
  categories.forEach((c) => { categoryNameMap[c.slug] = c.name })

  return (
    <Layout>
      {/* ── 1. Hero Slider — banner/carousel only, no overlay content ───────── */}
      <div className="s2-hero-section" style={{ position: 'relative', width: '100%', height: 'clamp(480px, 70vh, 700px)', overflow: 'hidden' }}>
        <Swiper
          modules={[Autoplay, Pagination, Navigation, EffectFade]}
          effect="fade"
          autoplay={{ delay: 4500, disableOnInteraction: false }}
          pagination={{ clickable: true }}
          navigation
          loop
          style={{ height: '100%', width: '100%' }}
        >
          {banners.map((src, i) => (
            <SwiperSlide key={i} style={{ position: 'relative' }}>
              <div
                style={{
                  position: 'absolute', inset: 0,
                  backgroundImage: `url(${src})`,
                  backgroundSize: 'cover',
                  backgroundPosition: 'center',
                }}
              />
            </SwiperSlide>
          ))}
        </Swiper>
      </div>

      {/* ── 2. Notice bar ─────────────────────────────────────────────────── */}
      <div className="s2-notice-bar s2-home-notice" style={{ borderBottom: '2px solid #ffc107' }}>
        🚨 <strong>Public Notice:</strong> Our only official website is <strong>{window.location.hostname}</strong>. Please verify all services only through our official channels.
        {whatsapp && (
          <> · <a href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`} style={{ color: '#2e7d32', fontWeight: 700 }}>WhatsApp Us</a></>
        )}
      </div>

      {/* ── 3. Services section ───────────────────────────────────────────── */}
      <section className="s2-section" style={{ background: 'var(--s2-color-surface, #fff)' }}>
        <div className="s2-container">
          <div className="s2-home-section-head">
            <p className="s2-t-eyebrow">What We Offer</p>
            <h2 className="s2-t-section-heading">Our Services</h2>
            <p className="s2-t-body" style={{ margin: 0 }}>Expert NRI assistance across 8 service categories</p>
          </div>

          {/* Category tabs */}
          <div className="s2-tabs">
            {categories.map((cat) => (
              <button
                key={cat.slug}
                onClick={() => setActiveCategory(cat.slug)}
                className={`s2-tab-btn${activeCategory === cat.slug ? ' active' : ''}`}
                style={{
                  '--s2-primary': primary,
                  padding: '12px 28px',
                  fontSize: 15,
                  fontWeight: activeCategory === cat.slug ? 700 : 500,
                  color: activeCategory === cat.slug ? primary : '#666',
                  background: 'none',
                  border: 'none',
                  borderBottom: activeCategory === cat.slug ? `3px solid ${primary}` : '3px solid transparent',
                  marginBottom: -2,
                  cursor: 'pointer',
                  transition: 'all .2s',
                  display: 'flex',
                  alignItems: 'center',
                  gap: 6,
                } as React.CSSProperties}
              >
                {cat.icon && <span style={{ fontSize: 18 }}>{cat.icon}</span>}
                {cat.name}
              </button>
            ))}
          </div>

          {/* Service cards */}
          <div className="s2-svc-grid">
            {displayServices.map((svc, i) => {
              const fallbackImgs = [IMAGES.property, IMAGES.housekeeping, IMAGES.tenancy, IMAGES.rent]
              const img = svc.image_url || (svc as Service & { img?: string }).img || fallbackImgs[i % 4]
              return (
                <div key={svc.id || i} className="s2-card s2-card--service s2-home-svc-card s2-animate-hover">
                  <div className="s2-home-svc-card__media">
                    <img src={img} alt={svc.name} loading="lazy" />
                    <div className="s2-home-svc-card__fade" />
                  </div>
                  <div className="s2-home-svc-card__body">
                    <h3 className="s2-home-svc-card__title">{svc.name}</h3>
                    <p className="s2-home-svc-card__desc">
                      {(svc.short_desc || '').slice(0, 110)}{(svc.short_desc || '').length > 110 ? '...' : ''}
                    </p>
                    <Link to={`/service/${svc.slug}`} className="s2-btn s2-btn--primary s2-btn--sm s2-home-svc-card__cta">
                      View Details →
                    </Link>
                  </div>
                </div>
              )
            })}
          </div>

          <div style={{ textAlign: 'center', marginTop: 28 }}>
            <Link to="/services" style={{ display: 'inline-flex', alignItems: 'center', gap: 8, color: primary, fontWeight: 700, fontSize: 15, borderBottom: `2px solid ${primary}`, paddingBottom: 3, textDecoration: 'none' }}>
              View All Services →
            </Link>
          </div>
        </div>
      </section>

      {/* ── 4. Cities grid ────────────────────────────────────────────────── */}
      <section style={{ background: '#F5F7FA', padding: '56px 20px' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto' }}>
          <h2 style={{ textAlign: 'center', fontSize: 'clamp(20px, 3vw, 30px)', fontWeight: 800, color: '#1E2D40', margin: '0 0 6px' }}>Property Management Cities</h2>
          <p style={{ textAlign: 'center', color: '#666', fontSize: 14, margin: '0 0 32px' }}>We manage NRI properties across all major Indian cities</p>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 16 }}>
            {displayCities.map(({ name, slug, img }) => (
              <Link
                key={slug || name}
                to={`/cities/property-management-in-${slug || name.toLowerCase()}`}
                style={{ textDecoration: 'none', borderRadius: 12, overflow: 'hidden', display: 'block', position: 'relative', height: 150, boxShadow: '0 2px 12px rgba(0,0,0,.1)', transition: 'transform .2s, box-shadow .2s' }}
                onMouseEnter={(e) => { e.currentTarget.style.transform = 'translateY(-3px)'; e.currentTarget.style.boxShadow = '0 8px 24px rgba(0,0,0,.2)' }}
                onMouseLeave={(e) => { e.currentTarget.style.transform = ''; e.currentTarget.style.boxShadow = '0 2px 12px rgba(0,0,0,.1)' }}
              >
                <img src={img} alt={name} loading="lazy" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                <div style={{ position: 'absolute', inset: 0, background: 'linear-gradient(transparent 40%, rgba(0,0,0,.7) 100%)', display: 'flex', alignItems: 'flex-end', padding: 14 }}>
                  <div>
                    <div style={{ fontSize: 11, color: 'rgba(255,255,255,.8)', fontWeight: 600, letterSpacing: 1, textTransform: 'uppercase' }}>Property Services in</div>
                    <div style={{ fontSize: 16, fontWeight: 700, color: '#fff' }}>{name}</div>
                  </div>
                </div>
              </Link>
            ))}
          </div>
        </div>
      </section>

      {/* ── 5. Stats bar ──────────────────────────────────────────────────── */}
      <section className="s2-hero-stat-bar s2-stats-bar">
        <div className="s2-stats-bar__grid">
          {stats.map(({ n, l }) => <StatCard key={l} number={n} label={l} primary={primary} />)}
        </div>
      </section>

      {/* ── 6. Tagline ────────────────────────────────────────────────────── */}
      <div style={{ background: '#fff', padding: '24px 20px', textAlign: 'center', borderBottom: '1px solid #f0f0f0' }}>
        <p style={{ fontSize: 'clamp(16px, 2.5vw, 22px)', fontWeight: 700, color: primary, fontStyle: 'italic', margin: 0 }}>
          "{settings.home_tagline || 'Forming strong and trusted connections with our clients'}"
        </p>
      </div>

      {/* ── 7. Why Choose Us ──────────────────────────────────────────────── */}
      <section style={{ background: '#F5F7FA', padding: '64px 20px' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto' }}>
          <p style={{ textAlign: 'center', fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3, color: primary, margin: '0 0 8px' }}>Why Choose Us</p>
          <h2 style={{ textAlign: 'center', fontSize: 'clamp(22px, 3vw, 34px)', fontWeight: 800, color: '#1E2D40', margin: '0 0 32px' }}>Why Our Customers Love Us</h2>
          <div className="s2-feat-grid">
            {WHY_CHOOSE.map(({ icon, title, sub }) => (
              <div
                key={title}
                style={{ background: '#fff', border: '1px solid #EBF0F8', borderRadius: 12, padding: '22px 20px', display: 'flex', gap: 16, transition: 'box-shadow .2s, transform .2s' }}
                onMouseEnter={(e) => { e.currentTarget.style.boxShadow = '0 6px 20px rgba(0,0,0,.1)'; e.currentTarget.style.transform = 'translateY(-2px)' }}
                onMouseLeave={(e) => { e.currentTarget.style.boxShadow = ''; e.currentTarget.style.transform = '' }}
              >
                <div style={{ width: 52, height: 52, background: `${primary}15`, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 24, flexShrink: 0 }}>{icon}</div>
                <div>
                  <h4 style={{ fontSize: 14, fontWeight: 700, color: '#1E2D40', margin: '0 0 5px' }}>{title}</h4>
                  <p style={{ fontSize: 13, color: '#666', lineHeight: 1.6, margin: 0 }}>{sub}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── 8. Testimonials ───────────────────────────────────────────────── */}
      <section style={{ background: '#fff', padding: '64px 20px' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto' }}>
          <p style={{ textAlign: 'center', fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3, color: primary, margin: '0 0 8px' }}>Client Testimonials</p>
          <h2 style={{ textAlign: 'center', fontSize: 'clamp(22px, 3vw, 34px)', fontWeight: 800, color: '#1E2D40', margin: '0 0 32px' }}>What Our Customers Say</h2>
          <Swiper
            modules={[Autoplay, Pagination]}
            autoplay={{ delay: 5000, disableOnInteraction: false }}
            pagination={{ clickable: true }}
            spaceBetween={24}
            slidesPerView={1}
            breakpoints={{ 768: { slidesPerView: 2 }, 1024: { slidesPerView: 3 } }}
            style={{ paddingBottom: 40 }}
          >
            {displayTestimonials.map((t, i) => (
              <SwiperSlide key={t.id || i}>
                <div style={{ border: '1px solid #EBF0F8', borderRadius: 14, padding: 24, height: '100%', display: 'flex', flexDirection: 'column', gap: 14 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
                    <div style={{ width: 52, height: 52, borderRadius: '50%', overflow: 'hidden', border: `3px solid ${primary}30`, flexShrink: 0, background: `${primary}20`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, color: primary, fontSize: 20 }}>
                      {t.image_url
                        ? <img src={t.image_url} alt={t.name} width={52} height={52} style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} />
                        : (t.name || '?')[0]
                      }
                    </div>
                    <div>
                      <div style={{ fontWeight: 700, fontSize: 15, color: '#1E2D40' }}>{t.name}</div>
                      <div style={{ fontSize: 12, color: '#888' }}>{t.location || t.loc}</div>
                    </div>
                  </div>
                  <div style={{ color: '#f59e0b', fontSize: 18, letterSpacing: 2 }}>{'★'.repeat(t.rating || 5)}</div>
                  <p style={{ fontSize: 14, color: '#444', lineHeight: 1.75, margin: 0, flex: 1 }}>{t.text || t.review}</p>
                </div>
              </SwiperSlide>
            ))}
          </Swiper>

          {/* Google review badge */}
          <div style={{ textAlign: 'center', marginTop: 8 }}>
            <div style={{ display: 'inline-flex', alignItems: 'center', gap: 14, border: '1px solid #EBF0F8', borderRadius: 12, padding: '12px 24px', background: '#fff', boxShadow: '0 2px 8px rgba(0,0,0,.06)' }}>
              <div style={{ width: 36, height: 36, background: '#4285f4', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontWeight: 900, fontSize: 20 }}>G</div>
              <div>
                <div style={{ fontWeight: 700, fontSize: 14, color: '#1E2D40' }}>Google Reviews</div>
                <div style={{ color: '#f59e0b', fontSize: 14 }}>
                  ★★★★★ <span style={{ color: '#888', fontSize: 13 }}>{settings.google_rating || '4.9'} / 5 · {settings.google_review_count || '500+'} reviews</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ── 9. How It Works ───────────────────────────────────────────────── */}
      <section style={{ background: '#F5F7FA', padding: '64px 20px' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto' }}>
          <p style={{ textAlign: 'center', fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3, color: primary, margin: '0 0 8px' }}>Simple Process</p>
          <h2 style={{ textAlign: 'center', fontSize: 'clamp(22px, 3vw, 34px)', fontWeight: 800, color: '#1E2D40', margin: '0 0 32px' }}>How It Works</h2>
          <div className="s2-how-grid">
            {HOW_IT_WORKS.map(({ n, icon, title, desc }) => (
              <div
                key={n}
                style={{ background: '#fff', border: '1px solid #EBF0F8', borderRadius: 12, padding: 28, textAlign: 'center', position: 'relative', transition: 'box-shadow .2s, transform .2s' }}
                onMouseEnter={(e) => { e.currentTarget.style.boxShadow = '0 6px 20px rgba(0,0,0,.1)'; e.currentTarget.style.transform = 'translateY(-3px)' }}
                onMouseLeave={(e) => { e.currentTarget.style.boxShadow = ''; e.currentTarget.style.transform = '' }}
              >
                <div style={{ position: 'absolute', top: 14, right: 16, width: 26, height: 26, background: `${primary}18`, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 800, color: primary }}>0{n}</div>
                <div style={{ width: 60, height: 60, background: primary, color: '#fff', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 26, margin: '0 auto 16px', boxShadow: `0 4px 14px ${primary}50` }}>{icon}</div>
                <h3 style={{ fontSize: 16, fontWeight: 700, color: '#1E2D40', margin: '0 0 8px' }}>{title}</h3>
                <p style={{ fontSize: 13, color: '#666', lineHeight: 1.7, margin: 0 }}>{desc}</p>
              </div>
            ))}
          </div>
          <div style={{ textAlign: 'center', marginTop: 32 }}>
            <Link to="/how-it-works" style={{ color: primary, fontWeight: 700, borderBottom: `2px solid ${primary}`, paddingBottom: 3, textDecoration: 'none', fontSize: 15 }}>
              Learn more about the full process →
            </Link>
          </div>
        </div>
      </section>

      {/* ── 10. As Featured In ────────────────────────────────────────────── */}
      <div style={{ background: '#fff', padding: '32px 20px', borderTop: '1px solid #f0f0f0', borderBottom: '1px solid #f0f0f0' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto', textAlign: 'center' }}>
          <p style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3, color: '#aaa', margin: '0 0 20px' }}>As Featured In</p>
          <div style={{ display: 'flex', gap: 20, justifyContent: 'center', flexWrap: 'wrap', alignItems: 'center' }}>
            {[['Inc42 Business', '#e65100'], ['Deccan Herald', '#4A6FA5'], ['Trackitt', '#2e7d32'], ['Economic Times', '#c62828'], ['YourStory', '#7b1fa2']].map(([name, color]) => (
              <div key={name} style={{ padding: '10px 24px', border: `1.5px solid ${color}30`, borderRadius: 8, fontWeight: 800, fontSize: 14, color, background: `${color}08` }}>{name}</div>
            ))}
          </div>
        </div>
      </div>

      {/* ── 11. Partners ──────────────────────────────────────────────────── */}
      <div style={{ background: '#F5F7FA', padding: '32px 20px' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto', textAlign: 'center' }}>
          <p style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3, color: '#aaa', margin: '0 0 18px' }}>Our Partners</p>
          <div style={{ display: 'flex', gap: 16, justifyContent: 'center', flexWrap: 'wrap' }}>
            {['ECE', 'NASBA', 'NACC', 'NACES', 'WES', 'CGFNS'].map((p) => (
              <div key={p} style={{ padding: '10px 22px', border: '1px solid #ddd', borderRadius: 8, fontWeight: 700, fontSize: 14, color: '#444', background: '#fff', boxShadow: '0 1px 4px rgba(0,0,0,.05)' }}>{p}</div>
            ))}
          </div>
        </div>
      </div>

      {/* ── 12. About section ─────────────────────────────────────────────── */}
      <section style={{ background: '#fff', padding: '64px 20px' }}>
        <div className="s2-mobile-stack" style={{ maxWidth: 1200, margin: '0 auto', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 48, alignItems: 'center' }}>
          <div>
            <p style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3, color: primary, margin: '0 0 10px' }}>About Us</p>
            <h2 style={{ fontSize: 'clamp(22px, 3vw, 34px)', fontWeight: 800, color: '#1E2D40', margin: '0 0 16px', lineHeight: 1.3 }}>
              {settings.about_heading || 'Your Trusted Partner for All NRI Services'}
            </h2>
            <p style={{ color: '#555', fontSize: 15, lineHeight: 1.85, margin: '0 0 16px' }}>
              {settings.about_text || 'We are a team of dedicated experts who specialize in NRI documentation, immigration, financial services, and property management. Our mission is to create a permanent digital solution for all NRI needs.'}
            </p>
            <p style={{ color: '#555', fontSize: 15, lineHeight: 1.85, margin: '0 0 28px' }}>
              Our expert team of lawyers, CAs, property managers, document specialists, and immigration consultants handles 44+ services across 8 domains — so you never need to worry about managing India from abroad.
            </p>
            <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
              <Link to="/about" style={{ background: primary, color: '#fff', padding: '12px 26px', borderRadius: 8, fontWeight: 700, fontSize: 15, textDecoration: 'none' }}>Know More →</Link>
              {whatsapp && (
                <a href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer" style={{ background: '#25d366', color: '#fff', padding: '12px 22px', borderRadius: 8, fontWeight: 600, fontSize: 15, textDecoration: 'none' }}>💬 Chat with Us</a>
              )}
            </div>
          </div>
          <div style={{ position: 'relative' }}>
            <img
              src={settings.about_image_url || IMAGES.about}
              alt="About Services2NRI"
              style={{ width: '100%', height: 320, objectFit: 'cover', borderRadius: 16, boxShadow: '0 8px 40px rgba(0,0,0,.15)' }}
            />
            <a
              href={settings.about_video_url || 'https://www.youtube.com/embed/edoXk8dW7ik'}
              target="_blank"
              rel="noopener noreferrer"
              style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', textDecoration: 'none' }}
            >
              <div
                style={{ width: 72, height: 72, background: 'rgba(255,255,255,.9)', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 4px 20px rgba(0,0,0,.25)', transition: 'transform .2s, box-shadow .2s' }}
                onMouseEnter={(e) => { e.currentTarget.style.transform = 'scale(1.1)'; e.currentTarget.style.boxShadow = '0 8px 32px rgba(0,0,0,.35)' }}
                onMouseLeave={(e) => { e.currentTarget.style.transform = ''; e.currentTarget.style.boxShadow = '0 4px 20px rgba(0,0,0,.25)' }}
              >
                <span style={{ fontSize: 28, marginLeft: 4, color: primary }}>▶</span>
              </div>
            </a>
          </div>
        </div>
      </section>

      {/* ── 13. Awards ────────────────────────────────────────────────────── */}
      <div style={{ background: 'linear-gradient(135deg, #fff8e1 0%, #fff3e0 100%)', padding: '32px 20px', borderTop: '1px solid #ffe0b2', borderBottom: '1px solid #ffe0b2', textAlign: 'center' }}>
        <p style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3, color: '#888', margin: '0 0 16px' }}>Awards We Have Received</p>
        <div style={{ display: 'flex', gap: 20, justifyContent: 'center', flexWrap: 'wrap' }}>
          <div style={{ display: 'inline-flex', alignItems: 'center', gap: 14, background: '#fff', border: '2px solid #ff6f00', borderRadius: 14, padding: '14px 32px', boxShadow: '0 4px 16px rgba(255,111,0,.15)' }}>
            <span style={{ fontSize: 34 }}>🏆</span>
            <span style={{ fontSize: 28, fontWeight: 900, color: '#ff6f00', fontStyle: 'italic' }}>#startupindia</span>
          </div>
          <div style={{ display: 'inline-flex', alignItems: 'center', gap: 14, background: '#fff', border: `2px solid ${primary}`, borderRadius: 14, padding: '14px 32px', boxShadow: `0 4px 16px ${primary}25` }}>
            <span style={{ fontSize: 34 }}>🎖️</span>
            <span style={{ fontSize: 18, fontWeight: 800, color: primary }}>Top NRI Service Platform 2024</span>
          </div>
        </div>
      </div>

      {/* ── 14. FAQ ───────────────────────────────────────────────────────── */}
      <section style={{ background: '#fff', padding: '64px 20px' }}>
        <div style={{ maxWidth: 860, margin: '0 auto' }}>
          <p style={{ textAlign: 'center', fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3, color: primary, margin: '0 0 8px' }}>FAQ</p>
          <h2 style={{ textAlign: 'center', fontSize: 'clamp(20px, 3vw, 32px)', fontWeight: 800, color: '#1E2D40', margin: '0 0 32px' }}>Let's Clear All The Doubts!</h2>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {FAQ_DATA.map(({ q, a }, i) => (
              <div key={i} style={{ border: '1px solid #EBF0F8', borderRadius: 12, overflow: 'hidden', transition: 'box-shadow .2s', boxShadow: openFaq === i ? '0 4px 16px rgba(0,0,0,.1)' : 'none' }}>
                <button
                  onClick={() => setOpenFaq(openFaq === i ? null : i)}
                  style={{ width: '100%', padding: '17px 22px', fontSize: 15, fontWeight: 600, cursor: 'pointer', display: 'flex', justifyContent: 'space-between', alignItems: 'center', textAlign: 'left', background: openFaq === i ? `${primary}08` : '#fff', border: 'none', color: '#1E2D40', gap: 12, transition: 'background .2s' }}
                >
                  <span>{q}</span>
                  <span style={{ width: 28, height: 28, borderRadius: '50%', background: openFaq === i ? primary : '#f0f0f0', color: openFaq === i ? '#fff' : '#666', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 18, flexShrink: 0, transition: 'all .2s' }}>
                    {openFaq === i ? '−' : '+'}
                  </span>
                </button>
                {openFaq === i && (
                  <div style={{ padding: '0 22px 18px', fontSize: 14, color: '#555', lineHeight: 1.85 }}>{a}</div>
                )}
              </div>
            ))}
          </div>
          <div style={{ textAlign: 'center', marginTop: 24 }}>
            <Link to="/faq" style={{ color: primary, fontWeight: 700, borderBottom: `2px solid ${primary}`, paddingBottom: 3, textDecoration: 'none', fontSize: 15 }}>
              View All FAQs →
            </Link>
          </div>
        </div>
      </section>

      {/* ── 15. Newsletter ────────────────────────────────────────────────── */}
      <section style={{ background: `linear-gradient(135deg, ${primary}15 0%, ${primary}05 100%)`, padding: '48px 20px', borderTop: '1px solid #EBF0F8', textAlign: 'center' }}>
        <h3 style={{ fontSize: 'clamp(18px, 2.5vw, 26px)', fontWeight: 800, color: '#1E2D40', margin: '0 0 8px' }}>Subscribe to Our Newsletter</h3>
        <p style={{ color: '#666', fontSize: 14, margin: '0 0 22px' }}>Stay updated on the latest NRI news, service launches, and important updates.</p>
        <div style={{ display: 'flex', gap: 8, maxWidth: 460, margin: '0 auto' }}>
          <input
            type="email"
            placeholder="Your email address"
            style={{ flex: 1, padding: '13px 18px', border: '1.5px solid #ddd', borderRadius: 9, fontSize: 14, outline: 'none', fontFamily: 'inherit', transition: 'border-color .2s' }}
            onFocus={(e) => (e.currentTarget.style.borderColor = primary)}
            onBlur={(e) => (e.currentTarget.style.borderColor = '#ddd')}
          />
          <button style={{ background: primary, color: '#fff', border: 'none', padding: '13px 22px', borderRadius: 9, fontWeight: 700, fontSize: 14, cursor: 'pointer', whiteSpace: 'nowrap' }}
            onMouseEnter={(e) => (e.currentTarget.style.opacity = '0.85')}
            onMouseLeave={(e) => (e.currentTarget.style.opacity = '1')}
          >
            Subscribe
          </button>
        </div>
      </section>

      {/* ── 16. App download ──────────────────────────────────────────────── */}
      <section style={{ background: `linear-gradient(135deg, #1E2D40 0%, ${primary} 50%, #5E87BF 100%)`, color: '#fff', padding: '56px 20px' }}>
        <div className="s2-mobile-stack" style={{ maxWidth: 1200, margin: '0 auto', display: 'grid', gridTemplateColumns: '1fr auto', gap: 32, alignItems: 'center' }}>
          <div>
            <p style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3, opacity: 0.75, margin: '0 0 10px' }}>Mobile App</p>
            <h3 style={{ fontSize: 'clamp(22px, 3vw, 36px)', fontWeight: 900, margin: '0 0 10px' }}>Download Our App</h3>
            <p style={{ opacity: 0.85, fontSize: 15, margin: '0 0 28px', lineHeight: 1.7, maxWidth: 500 }}>
              Manage all your NRI services from your smartphone anytime, anywhere. Track progress, upload documents, and communicate with our team on the go.
            </p>
            <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap' }}>
              {[
                { href: settings.app_playstore_url || '#', icon: '▶', line1: 'GET IT ON', line2: 'Google Play' },
                { href: settings.app_appstore_url || '#', icon: '🍎', line1: 'DOWNLOAD ON THE', line2: 'App Store' },
              ].map(({ href, icon, line1, line2 }) => (
                <a key={line2} href={href} target="_blank" rel="noopener noreferrer"
                  style={{ display: 'flex', alignItems: 'center', gap: 12, background: '#000', color: '#fff', padding: '13px 22px', borderRadius: 12, textDecoration: 'none', border: '1.5px solid rgba(255,255,255,.2)' }}
                  onMouseEnter={(e) => (e.currentTarget.style.background = '#111')}
                  onMouseLeave={(e) => (e.currentTarget.style.background = '#000')}
                >
                  <span style={{ fontSize: 28 }}>{icon}</span>
                  <div>
                    <div style={{ fontSize: 10, opacity: 0.7, letterSpacing: 1 }}>{line1}</div>
                    <div style={{ fontWeight: 700, fontSize: 15 }}>{line2}</div>
                  </div>
                </a>
              ))}
            </div>
          </div>
          <div style={{ fontSize: 120, opacity: 0.15, lineHeight: 1 }}>📱</div>
        </div>
      </section>

      {/* ── 17. Location pills ────────────────────────────────────────────── */}
      <div style={{ background: '#fff', padding: '20px', borderTop: '1px solid #f0f0f0' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto', textAlign: 'center' }}>
          <p style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3, color: '#aaa', margin: '0 0 12px' }}>Locations</p>
          <div style={{ display: 'flex', gap: 10, justifyContent: 'center', flexWrap: 'wrap' }}>
            {displayCities.map(({ name }) => (
              <Link
                key={name}
                to="/services/property"
                style={{ padding: '6px 16px', border: '1px solid #EBF0F8', borderRadius: 99, fontSize: 13, color: '#444', fontWeight: 500, textDecoration: 'none', transition: 'all .2s', background: '#fff' }}
                onMouseEnter={(e) => { e.currentTarget.style.background = primary; e.currentTarget.style.color = '#fff'; e.currentTarget.style.borderColor = primary }}
                onMouseLeave={(e) => { e.currentTarget.style.background = '#fff'; e.currentTarget.style.color = '#444'; e.currentTarget.style.borderColor = '#EBF0F8' }}
              >
                📍 {name}
              </Link>
            ))}
          </div>
        </div>
      </div>
    </Layout>
  )
}
