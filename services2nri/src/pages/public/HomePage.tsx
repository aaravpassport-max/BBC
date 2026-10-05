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

import React, { useState, useEffect, useMemo } from 'react'
import { cssVars } from '@/lib/design-tokens'
import { getRuntimeDesignConfig } from '@/lib/apply-design-config'
import { DESIGN_UPDATED_EVENT } from '@/lib/design-live-sync'
import { homeHeroWidthStyle } from '@/lib/width-layout'
import { Link } from 'react-router-dom'
import { Swiper, SwiperSlide } from 'swiper/react'
import { Autoplay, Pagination, Navigation, EffectFade } from 'swiper/modules'
import 'swiper/css'
import 'swiper/css/pagination'
import 'swiper/css/navigation'
import 'swiper/css/effect-fade'

import { Layout } from '@/components/layout/Layout'
import { PublicSectionHead } from '@/components/public/PublicLayout'
import { CmsElement } from '@/components/public/CmsElement'
import { useStore } from '@/lib/store'
import { api } from '@/lib/api'
import { subscribeResource } from '@/lib/resource-cache'
import { parseHeroBanners, IMAGES, getAvatarImage } from '@/lib/images'
import type { Category, Service, City, Testimonial } from '@/types'
import {
  parseAwardBadges,
  parseHomeFaqPairs,
  parseLogoChips,
  parseStringList,
  parseWhyChooseCards,
} from '@/lib/home-content-settings'
import { DEFAULT_HOME_SECTION_ORDER } from '@/lib/home-section-order'
import { sectionHidden } from '@/lib/section-visibility'
import { bandPadClass, pickCssStyle } from '@/lib/responsive-band-padding'
import {
  mergeLegacyHomeOrder,
  orderIdsForPage,
  parsePageSectionOrders,
  sectionOrderStyle,
} from '@/lib/page-section-order'

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

function StatCard({ number, label }: { number: string; label: string }) {
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
    <div ref={setRef} className="s2-stats-bar__item">
      <div className="s2-stats-bar__value">{value}</div>
      <div className="s2-stats-bar__label">{label}</div>
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

const FEATURED_IN = [
  { name: 'Inc42 Business', brand: 'inc42' },
  { name: 'Deccan Herald', brand: 'deccan' },
  { name: 'Trackitt', brand: 'trackitt' },
  { name: 'Economic Times', brand: 'et' },
  { name: 'YourStory', brand: 'yourstory' },
] as const

const FAQ_DATA = [
  { q: '1. What kind of services do you provide?',          a: 'We provide 44+ services across 8 categories — documentation, education, OCI/passport/visa, USCIS, property management, financial services, legal services, and taxation. All designed specifically for NRIs worldwide.' },
  { q: '2. What is your service fee structure?',           a: 'All services are quote-based. After you submit your requirements, our expert team reviews and sends a transparent, itemised quote within 24 hours. You only pay after approving the quote — no surprises.' },
  { q: '3. Are my documents secured in your system?',     a: 'Yes. All documents are stored with end-to-end encryption using AES-256. We maintain strict PII (Personally Identifiable Information) confidentiality and never share documents with third parties.' },
  { q: '4. How can you make sure documents are not misused?', a: 'We maintain strict document confidentiality protocols. Documents are accessed only by the assigned team member and are used solely for the requested service. Documents are deleted after service completion.' },
  { q: '5. What type of payment is accepted?',            a: 'We accept bank transfer (NEFT/RTGS/UPI/SWIFT), and online payment via Razorpay (credit/debit cards, UPI). International payments via SWIFT in INR — your bank converts at prevailing exchange rates.' },
]

export function HomePage() {
  const settings = useStore((s) => s.settings)
  const whatsapp = settings.platform_whatsapp

  // Data state
  const [activeCategory, setActiveCategory] = useState('')
  const [categories, setCategories]         = useState<Category[]>([])
  const [serviceMap, setServiceMap]         = useState<Record<string, Service[]>>({})
  const [cities, setCities]                 = useState<Array<{ name: string; slug: string; img: string }>>([])
  const [testimonials, setTestimonials]     = useState<Testimonial[]>([])
  const [openFaq, setOpenFaq]               = useState<number | null>(null)
  const [designTick, setDesignTick]         = useState(0)

  useEffect(() => {
    const bump = () => setDesignTick((n) => n + 1)
    window.addEventListener(DESIGN_UPDATED_EVENT, bump)
    return () => window.removeEventListener(DESIGN_UPDATED_EVENT, bump)
  }, [])

  const heroWidthStyle = useMemo(() => {
    void designTick
    const design = getRuntimeDesignConfig()?.design as Record<string, unknown> | undefined
    return homeHeroWidthStyle(design)
  }, [designTick])

  const banners = parseHeroBanners(settings.hero_banners)

  const stats = [
    { n: settings.stat_1_number || '10,000+', l: settings.stat_1_label || 'Happy Clients' },
    { n: settings.stat_2_number || '44+',     l: settings.stat_2_label || 'Services' },
    { n: settings.stat_3_number || '40+',     l: settings.stat_3_label || 'Locations' },
    { n: settings.stat_4_number || '23+',     l: settings.stat_4_label || 'Countries Served' },
  ]

  useEffect(() => {
    const unsubs: Array<() => void> = []

    const loadCategoryServices = (cats: Category[]) => {
      cats.forEach((cat) => {
        const path = `services?surface=homepage&category=${cat.slug}&per_page=4`
        const cached = api.peekGet<{ services: Service[] }>(path)
        if (cached?.services?.length) {
          setServiceMap((prev) => ({ ...prev, [cat.slug]: cached.services || [] }))
        }
        void api.getCached<{ services: Service[] }>(path)
          .then((r) => setServiceMap((prev) => ({ ...prev, [cat.slug]: r.services || [] })))
          .catch(() => {})
        unsubs.push(
          subscribeResource('GET', path, (fresh) => {
            const row = fresh as { services?: Service[] }
            if (row.services?.length) {
              setServiceMap((prev) => ({ ...prev, [cat.slug]: row.services || [] }))
            }
          }),
        )
      })
    }

    const applyCategories = (data: { categories?: Category[] }) => {
      const cats = data.categories || []
      if (!cats.length) return
      setCategories(cats)
      setActiveCategory((prev) => prev || cats[0].slug)
      loadCategoryServices(cats)
    }

    const fallbackCategories: Category[] = [
      { id: 1, slug: 'property', name: 'Property', icon: '🏠', color: '#4A6FA5' },
      { id: 2, slug: 'financial', name: 'Financial', icon: '💰', color: '#7b1fa2' },
      { id: 3, slug: 'immigration', name: 'Immigration', icon: '✈️', color: '#c62828' },
      { id: 4, slug: 'education', name: 'Education', icon: '🎓', color: '#00695c' },
    ]

    const catsPath = 'categories?surface=homepage'
    const cachedCats = api.peekGet<{ categories: Category[] }>(catsPath)
    if (cachedCats?.categories?.length) applyCategories(cachedCats)

    void api.getCached<{ categories: Category[] }>(catsPath)
      .then(applyCategories)
      .catch(() => {
        setCategories(fallbackCategories)
        setActiveCategory('property')
        loadCategoryServices(fallbackCategories)
      })
    unsubs.push(
      subscribeResource('GET', catsPath, (fresh) => applyCategories(fresh as { categories?: Category[] })),
    )

    const citiesPath = 'cities?surface=homepage'
    const applyCities = (data: { cities?: City[] }) => {
      const c = data.cities || []
      if (!c.length) return
      setCities(
        c.map((city) => ({
          name: city.name,
          slug: city.slug,
          img: city.image_url || IMAGES[city.slug] || IMAGES.pune,
        })),
      )
    }
    const cachedCities = api.peekGet<{ cities: City[] }>(citiesPath)
    if (cachedCities) applyCities(cachedCities)
    void api.getCached<{ cities: City[] }>(citiesPath).then(applyCities).catch(() => {})
    unsubs.push(subscribeResource('GET', citiesPath, (fresh) => applyCities(fresh as { cities?: City[] })))

    const testimonialsPath = 'testimonials'
    const applyTestimonials = (data: { testimonials?: Testimonial[] }) => {
      const t = (data.testimonials || []).filter((x) => x.is_active !== false)
      if (t.length) setTestimonials(t)
    }
    const cachedTestimonials = api.peekGet<{ testimonials: Testimonial[] }>(testimonialsPath)
    if (cachedTestimonials) applyTestimonials(cachedTestimonials)
    void api.getCached<{ testimonials: Testimonial[] }>(testimonialsPath).then(applyTestimonials).catch(() => {})
    unsubs.push(
      subscribeResource('GET', testimonialsPath, (fresh) =>
        applyTestimonials(fresh as { testimonials?: Testimonial[] }),
      ),
    )

    return () => {
      unsubs.forEach((off) => off())
    }
  }, [])

  const displayServices =
    (serviceMap[activeCategory]?.length ? serviceMap[activeCategory] : null) ??
    FALLBACK_SERVICES[activeCategory] ??
    []

  const displayCities = cities.length > 0 ? cities : FALLBACK_CITIES
  const displayTestimonials = testimonials.length > 0 ? testimonials : FALLBACK_TESTIMONIALS

  const categoryNameMap: Record<string, string> = {}
  categories.forEach((c) => { categoryNameMap[c.slug] = c.name })

  const heroBandStyle: React.CSSProperties = {
    ...pickCssStyle(settings, {
      bg: 'css_hero_bg',
      padding: 'css_hero_padding',
      color: 'css_hero_textcolor',
    }),
  }
  if (settings.css_hero_minheight) heroBandStyle.minHeight = settings.css_hero_minheight
  const heroOverlayStyle: React.CSSProperties = {}
  if (settings.hero_overlay_color) heroOverlayStyle.backgroundColor = settings.hero_overlay_color
  if (settings.hero_overlay_opacity) heroOverlayStyle.opacity = settings.hero_overlay_opacity

  const howSteps = [1, 2, 3, 4, 5, 6].map((n) => {
    const fb = HOW_IT_WORKS[n - 1]
    const s = settings as Record<string, string>
    const title = s[`hiw_step${n}_title`] || fb?.title || ''
    const desc = s[`hiw_step${n}_desc`] || fb?.desc || ''
    return { n, icon: fb?.icon || '✓', title, desc }
  })

  const whyChoose = parseWhyChooseCards(settings.home_why_choose_json, WHY_CHOOSE)
  const homeFaqs = parseHomeFaqPairs(settings.home_faq_json, FAQ_DATA)
  const pressLogos = parseLogoChips(
    settings.home_press_json,
    FEATURED_IN.map((x) => ({ name: x.name, brand: x.brand })),
  )
  const partnerChips = parseStringList(
    settings.home_partners_json,
    ['ECE', 'NASBA', 'NACC', 'NACES', 'WES', 'CGFNS'],
  )
  const awardBadges = parseAwardBadges(settings.home_awards_json, [
    { emoji: '🏆', text: '#startupindia', variant: 'orange' },
    { emoji: '🎖️', text: 'Top NRI Service Platform 2024', variant: 'primary' },
  ])

  const defaultNotice = `Our only official website is ${typeof window !== 'undefined' ? window.location.hostname : 'this domain'}. Please verify all services only through our official channels.`

  const heroPrimaryCta = settings.hero_cta_text || 'Browse services'
  const heroPrimaryUrl = settings.hero_cta_url || '/services'
  const heroSecondaryCta = settings.hero_cta2_text || 'Get a quote'
  const heroSecondaryUrl = settings.hero_cta2_url || '/contact'

  const homeSectionOrder = useMemo(() => {
    const orders = mergeLegacyHomeOrder(
      parsePageSectionOrders(settings.public_page_section_orders_json),
      settings.home_section_order_json,
    )
    return orderIdsForPage(orders, 'home', DEFAULT_HOME_SECTION_ORDER, settings.home_section_order_json)
  }, [settings.home_section_order_json, settings.public_page_section_orders_json])
  const bandOrder = (id: string) => sectionOrderStyle(id, homeSectionOrder)

  return (
    <Layout>
      <div className="s2-home-page">
      {/* ── 1. Hero slider + mobile-first headline & CTAs ─────────────────── */}
      {!sectionHidden(settings, 'hide_section_hero') && (
      <div
        className={`s2-home-band s2-hero-section s2-surface-media ${bandPadClass(settings, 'css_hero_padding')}`}
        data-s2-section="hero"
        data-home-section-id="hero"
        style={{ ...bandOrder('hero'), ...heroWidthStyle, ...heroBandStyle }}
      >
        <Swiper
          className="s2-home-hero-swiper"
          modules={[Autoplay, Pagination, Navigation, EffectFade]}
          effect="fade"
          autoplay={{ delay: 4500, disableOnInteraction: false }}
          pagination={{ clickable: true }}
          navigation
          loop
        >
          {banners.map((src, i) => (
            <SwiperSlide key={i} className="s2-home-hero-slide">
              <div className="s2-home-hero-slide-bg" style={cssVars({ 's2-home-slide-bg': `url(${src})` })} />
            </SwiperSlide>
          ))}
        </Swiper>
        <div className="s2-home-hero-overlay" aria-hidden={false} style={Object.keys(heroOverlayStyle).length ? heroOverlayStyle : undefined}>
          <div className="s2-home-hero-overlay__inner">
            <p className="s2-home-hero-overlay__eyebrow">{settings.hero_subheading || 'Trusted NRI partner'}</p>
            <h1 className="s2-home-hero-overlay__title">
              {settings.hero_heading_1 ? (
                <>
                  {settings.hero_heading_1}
                  {settings.hero_heading_2 ? (
                    <span className="s2-home-hero-overlay__accent"> {settings.hero_heading_2}</span>
                  ) : null}
                </>
              ) : (
                settings.home_hero_title || settings.platform_tagline || 'Your India services, managed from anywhere'
              )}
            </h1>
            <p className="s2-home-hero-overlay__sub">
              {settings.hero_description ||
                settings.home_hero_subtitle ||
                'Property, documents, tax & 44+ expert services — one secure platform with 24/7 support.'}
            </p>
            <div className="s2-home-hero-overlay__actions">
              <CmsElement pageId="home" sectionKey="hero" elementId="primary_button">
                <Link to={heroPrimaryUrl} className="s2-btn s2-btn--primary s2-home-hero-overlay__cta">
                  {heroPrimaryCta}
                </Link>
              </CmsElement>
              <CmsElement pageId="home" sectionKey="hero" elementId="secondary_button">
                <Link to={heroSecondaryUrl} className="s2-btn s2-btn--outline s2-home-hero-overlay__cta s2-home-hero-overlay__cta--ghost">
                  {heroSecondaryCta}
                </Link>
              </CmsElement>
            </div>
          </div>
        </div>
      </div>
      )}

      {!sectionHidden(settings, 'hide_section_notice') && (
      <div
        className={`s2-home-band s2-notice-bar s2-home-notice ${bandPadClass(settings, 'css_notice_padding')}`}
        data-s2-section="notice"
        data-home-section-id="notice"
        style={{ ...bandOrder('notice'), ...pickCssStyle(settings, { bg: 'css_notice_bg', padding: 'css_notice_padding', color: 'css_notice_color' }) }}
      >
        🚨 <strong>Public Notice:</strong>{' '}
        <CmsElement pageId="home" sectionKey="notice" elementId="body">
          {settings.home_notice_text || defaultNotice}
        </CmsElement>
        {whatsapp && (
          <>
            {' '}
            ·{' '}
            <CmsElement pageId="home" sectionKey="notice" elementId="link">
              <a href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`} className="s2-home-notice__wa">
                {settings.home_notice_whatsapp_label || 'WhatsApp Us'}
              </a>
            </CmsElement>
          </>
        )}
      </div>
      )}

      {!sectionHidden(settings, 'hide_section_search') && (
      <section
        className={`s2-home-band s2-home-search s2-experience-section ${bandPadClass(settings, 'css_search_padding')}`}
        data-s2-section="search"
        data-home-section-id="search"
        data-s2-reveal=""
        style={{ ...bandOrder('search'), ...pickCssStyle(settings, { bg: 'css_search_bg', padding: 'css_search_padding' }) }}
      >
        <div className="s2-container s2-section-inner s2-width-standard">
          <PublicSectionHead
            title={settings.home_search_title || 'Find your service'}
            subtitle={settings.home_search_subtitle || 'Search 44+ NRI services across every category'}
          />
          <form
            className="s2-home-search__form"
            action="/services"
            method="get"
            onSubmit={(e) => {
              e.preventDefault()
              const fd = new FormData(e.currentTarget)
              const q = String(fd.get('q') || '').trim()
              window.location.href = q ? `/services?q=${encodeURIComponent(q)}` : '/services'
            }}
          >
            <CmsElement pageId="home" sectionKey="search" elementId="collection">
              <input
                type="search"
                name="q"
                className="s2-home-search__input"
                placeholder={settings.home_search_placeholder || 'Search services…'}
                aria-label="Search services"
              />
            </CmsElement>
            <CmsElement pageId="home" sectionKey="search" elementId="primary_button">
              <button type="submit" className="s2-btn s2-btn--primary s2-home-search__btn">
                {settings.home_search_button || 'Search'}
              </button>
            </CmsElement>
          </form>
        </div>
      </section>
      )}

      {!sectionHidden(settings, 'hide_section_services') && (
      <section
        className="s2-home-band s2-section s2-marketing-section s2-experience-section"
        data-s2-section="home_services"
        data-home-section-id="services"
        data-s2-reveal=""
        style={{ ...bandOrder('services'), ...pickCssStyle(settings, { bg: 'css_svc_bg', padding: undefined }) }}
      >
        <div className="s2-container s2-section-inner s2-width-standard">
          <PublicSectionHead
            eyebrow={settings.services_eyebrow || 'What We Offer'}
            title={settings.services_title || 'Our Services'}
            subtitle={settings.services_subtitle || 'Expert NRI assistance across 8 service categories'}
          />

          {/* Category tabs */}
          <div className="s2-tabs">
            {categories.map((cat) => (
              <button
                key={cat.slug}
                type="button"
                onClick={() => setActiveCategory(cat.slug)}
                className={`s2-tab-btn${activeCategory === cat.slug ? ' active' : ''}`}
              >
                {cat.icon && <span className="s2-tab-btn__icon">{cat.icon}</span>}
                {cat.name}
              </button>
            ))}
          </div>

          {/* Service cards */}
          <div
            className="s2-svc-grid s2-stagger"
            style={{
              ...(settings.css_svc_cols
                ? { gridTemplateColumns: `repeat(${Math.min(6, Math.max(1, parseInt(settings.css_svc_cols, 10) || 4))}, 1fr)` }
                : {}),
              ...(settings.css_svc_gap ? { gap: settings.css_svc_gap } : {}),
            }}
          >
            {displayServices.map((svc, i) => {
              const fallbackImgs = [IMAGES.property, IMAGES.housekeeping, IMAGES.tenancy, IMAGES.rent]
              const img = svc.image_url || (svc as Service & { img?: string }).img || fallbackImgs[i % 4]
              return (
                <div
                  key={svc.id || i}
                  className="s2-card s2-card--service s2-card--media-bleed s2-home-svc-card s2-animate-hover"
                  style={settings.css_svc_card_bg ? { background: settings.css_svc_card_bg } : undefined}
                >
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

          <div className="s2-home-section-cta">
            <Link to="/services" className="s2-home-text-link">
              {settings.services_view_all_text || 'View All Services →'}
            </Link>
          </div>
        </div>
      </section>
      )}

      {!sectionHidden(settings, 'hide_section_cities') && (
      <section
        className={`s2-home-band s2-marketing-section s2-marketing-section--alt s2-experience-section ${bandPadClass(settings, 'css_cities_padding')}`}
        data-s2-section="cities"
        data-home-section-id="cities"
        data-s2-reveal=""
        style={{ ...bandOrder('cities'), ...pickCssStyle(settings, { bg: 'css_cities_bg', padding: 'css_cities_padding' }) }}
      >
        <div className="s2-container s2-section-inner s2-width-standard">
          <PublicSectionHead
            title={settings.cities_section_title || 'Property Management Cities'}
            subtitle={settings.cities_section_subtitle || 'We manage NRI properties across all major Indian cities'}
          />
          <div className="s2-city-grid s2-city-grid--mobile-rail">
            {displayCities.map(({ name, slug, img }) => (
              <Link
                key={slug || name}
                to={`/cities/property-management-in-${slug || name.toLowerCase()}`}
                className="s2-home-city-card"
              >
                <img src={img} alt={name} loading="lazy" />
                <div className="s2-home-city-card__overlay">
                  <div>
                    <div className="s2-home-city-card__eyebrow">{settings.cities_card_eyebrow || 'Property Services in'}</div>
                    <div className="s2-home-city-card__name">{name}</div>
                  </div>
                </div>
              </Link>
            ))}
          </div>
        </div>
      </section>
      )}

      {!sectionHidden(settings, 'hide_section_stats') && (
      <section
        className={`s2-home-band s2-hero-stat-bar s2-stats-bar s2-surface-dark ${bandPadClass(settings, 'css_stats_padding')}`}
        data-s2-section="stats"
        data-home-section-id="stats"
        style={{ ...bandOrder('stats'), ...pickCssStyle(settings, { bg: 'css_stats_bg', padding: 'css_stats_padding', color: 'css_stats_color' }) }}
      >
        <div className="s2-stats-bar__grid">
          {stats.map(({ n, l }) => <StatCard key={l} number={n} label={l} />)}
        </div>
      </section>
      )}

      {!sectionHidden(settings, 'hide_section_tagline') && (
      <div
        className="s2-home-band s2-home-tagline s2-hero-quote-wrap"
        data-s2-section="tagline"
        data-home-section-id="tagline"
        style={{ ...bandOrder('tagline'), ...pickCssStyle(settings, { bg: 'css_tagline_bg', color: 'css_tagline_color' }) }}
      >
        <p style={settings.css_tagline_size ? { fontSize: settings.css_tagline_size } : undefined}>
          "{settings.home_tagline || 'Forming strong and trusted connections with our clients'}"
        </p>
      </div>
      )}

      {!sectionHidden(settings, 'hide_section_features') && (
      <section
        className={`s2-home-band s2-marketing-section s2-marketing-section--alt s2-experience-section ${bandPadClass(settings, 'css_features_padding')}`}
        data-s2-section="features"
        data-home-section-id="features"
        data-s2-reveal=""
        style={{ ...bandOrder('features'), ...pickCssStyle(settings, { bg: 'css_features_bg', padding: 'css_features_padding' }) }}
      >
        <div className="s2-container s2-section-inner s2-width-standard">
          <PublicSectionHead
            eyebrow={settings.features_eyebrow || 'Why Choose Us'}
            title={settings.features_title || 'Why Our Customers Love Us'}
          />
          <div className="s2-feat-grid s2-stagger">
            {whyChoose.map(({ icon, title, sub }) => (
              <div key={title} className="s2-home-feat-card">
                <div className="s2-home-feat-card__icon">{icon}</div>
                <div>
                  <h4 className="s2-home-feat-card__title">{title}</h4>
                  <p className="s2-home-feat-card__sub">{sub}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>
      )}

      {!sectionHidden(settings, 'hide_section_testimonials') && (
      <section
        className={`s2-home-band s2-marketing-section s2-experience-section ${bandPadClass(settings, 'css_testimonials_padding')}`}
        data-s2-section="testimonials"
        data-home-section-id="testimonials"
        data-s2-reveal=""
        style={{ ...bandOrder('testimonials'), ...pickCssStyle(settings, { bg: 'css_testimonials_bg', padding: 'css_testimonials_padding' }) }}
      >
        <div className="s2-container s2-section-inner s2-width-standard">
          <PublicSectionHead
            eyebrow={settings.testimonials_eyebrow || 'Client Testimonials'}
            title={settings.testimonials_title || 'What Our Customers Say'}
          />
          <Swiper
            className="s2-home-testimonials-swiper"
            modules={[Autoplay, Pagination]}
            autoplay={{ delay: 5000, disableOnInteraction: false }}
            pagination={{ clickable: true }}
            spaceBetween={24}
            slidesPerView={1}
            breakpoints={{ 768: { slidesPerView: 2 }, 1024: { slidesPerView: 3 } }}
          >
            {displayTestimonials.map((t, i) => (
              <SwiperSlide key={t.id || i}>
                <div className="s2-home-testimonial-card">
                  <div className="s2-home-testimonial-card__head">
                    <div className="s2-home-testimonial-card__avatar">
                      {t.image_url
                        ? <img src={t.image_url} alt={t.name} width={52} height={52} />
                        : (t.name || '?')[0]
                      }
                    </div>
                    <div>
                      <div className="s2-home-testimonial-card__name">{t.name}</div>
                      <div className="s2-home-testimonial-card__loc">{t.location || t.loc}</div>
                    </div>
                  </div>
                  <div className="s2-home-testimonial-card__stars">{'★'.repeat(t.rating || 5)}</div>
                  <p className="s2-home-testimonial-card__text">{t.text || t.review}</p>
                </div>
              </SwiperSlide>
            ))}
          </Swiper>

          <div className="s2-home-google-badge-wrap">
            <div className="s2-home-google-badge">
              <div className="s2-home-google-badge__icon">G</div>
              <div>
                <div className="s2-home-google-badge__title">Google Reviews</div>
                <div className="s2-home-google-badge__meta">
                  ★★★★★ <span>{settings.google_rating || '4.9'} / 5 · {settings.google_review_count || '500+'} reviews</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
      )}

      {!sectionHidden(settings, 'hide_section_how') && (
      <section
        className="s2-home-band s2-marketing-section s2-marketing-section--alt s2-experience-section"
        data-s2-section="process"
        data-home-section-id="how"
        data-s2-reveal=""
        style={{ ...bandOrder('how'), ...pickCssStyle(settings, { bg: 'css_how_bg', padding: undefined }) }}
      >
        <div className="s2-container s2-section-inner s2-width-standard">
          <PublicSectionHead eyebrow={settings.hiw_eyebrow || 'Simple Process'} title={settings.hiw_title || 'How It Works'} />
          <div
            className="s2-how-grid s2-stagger"
            style={{
              ...(settings.css_how_cols
                ? { gridTemplateColumns: `repeat(${Math.min(6, Math.max(1, parseInt(settings.css_how_cols, 10) || 3))}, 1fr)` }
                : {}),
              ...(settings.css_how_gap ? { gap: settings.css_how_gap } : {}),
            }}
          >
            {howSteps.map(({ n, icon, title, desc }) => (
              <div key={n} className="s2-home-how-card">
                <div className="s2-home-how-card__num">0{n}</div>
                <div className="s2-home-how-card__icon">{icon}</div>
                <h3 className="s2-home-how-card__title">{title}</h3>
                <p className="s2-home-how-card__desc">{desc}</p>
              </div>
            ))}
          </div>
          <div className="s2-home-how-cta">
            <Link to="/how-it-works" className="s2-home-text-link">
              {settings.hiw_footer_link_text || 'Learn more about the full process →'}
            </Link>
          </div>
        </div>
      </section>
      )}

      {!sectionHidden(settings, 'hide_section_press') && (
      <div
        className="s2-home-band s2-home-logo-strip s2-marketing-section s2-experience-section"
        data-s2-section="press"
        data-home-section-id="press"
        data-s2-reveal=""
        style={{ ...bandOrder('press'), ...pickCssStyle(settings, { bg: 'css_press_bg' }) }}
      >
        <p className="s2-home-logo-strip__label">{settings.home_press_label || 'As Featured In'}</p>
        <div className="s2-home-logo-strip__row s2-stagger">
          {pressLogos.map(({ name, brand }) => (
            <div key={name} className="s2-home-press-chip" data-brand={brand}>{name}</div>
          ))}
        </div>
      </div>
      )}

      {!sectionHidden(settings, 'hide_section_partners') && (
      <div
        className="s2-home-band s2-home-logo-strip s2-home-logo-strip--alt s2-experience-section"
        data-s2-section="partners"
        data-home-section-id="partners"
        data-s2-reveal=""
        style={{ ...bandOrder('partners'), ...pickCssStyle(settings, { bg: 'css_partners_bg' }) }}
      >
        <p className="s2-home-logo-strip__label">{settings.home_partners_label || 'Our Partners'}</p>
        <div className="s2-home-logo-strip__row s2-stagger">
          {partnerChips.map((p) => (
            <div key={p} className="s2-home-partner-chip">{p}</div>
          ))}
        </div>
      </div>
      )}

      {!sectionHidden(settings, 'hide_section_about') && (
      <section
        className={`s2-home-band s2-marketing-section s2-experience-section ${bandPadClass(settings, 'css_about_padding')}`}
        data-s2-section="about"
        data-home-section-id="about"
        data-s2-reveal=""
        style={{ ...bandOrder('about'), ...pickCssStyle(settings, { bg: 'css_about_bg', padding: 'css_about_padding' }) }}
      >
        <div className="s2-home-about-grid s2-mobile-stack s2-container s2-section-inner s2-width-wide">
          <div>
            <p className="s2-t-eyebrow">{settings.about_eyebrow || 'About Us'}</p>
            <h2 className="s2-t-section-heading">
              {settings.about_heading || 'Your Trusted Partner for All NRI Services'}
            </h2>
            <p className="s2-t-body">
              {settings.about_text || 'We are a team of dedicated experts who specialize in NRI documentation, immigration, financial services, and property management. Our mission is to create a permanent digital solution for all NRI needs.'}
            </p>
            <p className="s2-t-body">
              {settings.about_text_secondary ||
                'Our expert team of lawyers, CAs, property managers, document specialists, and immigration consultants handles 44+ services across 8 domains — so you never need to worry about managing India from abroad.'}
            </p>
            <div className="s2-home-about__actions">
              <Link to="/about" className="s2-btn s2-btn--primary">{settings.about_cta_text || 'Know More →'}</Link>
              {whatsapp && (
                <a
                  href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="s2-btn s2-btn--whatsapp"
                >
                  {settings.about_whatsapp_cta || '💬 Chat with Us'}
                </a>
              )}
            </div>
          </div>
          <div className="s2-home-about__img-wrap">
            <img
              src={settings.about_image_url || IMAGES.about}
              alt="About Services2NRI"
              className="s2-home-about__img"
            />
            <a
              href={settings.about_video_url || 'https://www.youtube.com/embed/edoXk8dW7ik'}
              target="_blank"
              rel="noopener noreferrer"
              className="s2-home-about__video-link"
              aria-label="Watch introduction video"
            >
              <div className="s2-home-about__play">
                <span aria-hidden>▶</span>
              </div>
            </a>
          </div>
        </div>
      </section>
      )}

      {!sectionHidden(settings, 'hide_section_awards') && (
      <div
        className="s2-home-band s2-home-awards s2-experience-section"
        data-s2-section="awards"
        data-home-section-id="awards"
        data-s2-reveal=""
        style={{ ...bandOrder('awards'), ...pickCssStyle(settings, { bg: 'css_awards_bg' }) }}
      >
        <p className="s2-home-logo-strip__label">{settings.home_awards_label || 'Awards We Have Received'}</p>
        <div className="s2-home-awards__row">
          {awardBadges.map((a) => (
            <div
              key={a.text}
              className={`s2-home-award s2-home-award--${a.variant === 'primary' ? 'primary' : 'orange'}`}
            >
              <span className="s2-home-award__emoji">{a.emoji}</span>
              <span className={a.variant === 'primary' ? 's2-home-award__text-primary' : 's2-home-award__text-orange'}>
                {a.text}
              </span>
            </div>
          ))}
        </div>
      </div>
      )}

      {!sectionHidden(settings, 'hide_section_faq') && (
      <section
        className="s2-home-band s2-marketing-section s2-experience-section"
        data-s2-section="faq"
        data-home-section-id="faq"
        data-s2-reveal=""
        style={{ ...bandOrder('faq'), ...pickCssStyle(settings, { bg: 'css_faq_bg' }) }}
      >
        <div className="s2-home-faq s2-container s2-section-inner s2-width-narrow">
          <PublicSectionHead
            eyebrow={settings.faq_section_eyebrow || 'FAQ'}
            title={settings.faq_section_title || "Let's Clear All The Doubts!"}
          />
          <div className="s2-home-faq__list">
            {homeFaqs.map(({ q, a }, i) => (
              <div key={i} className={`s2-home-faq__item${openFaq === i ? ' is-open' : ''}`}>
                <button
                  type="button"
                  onClick={() => setOpenFaq(openFaq === i ? null : i)}
                  className="s2-home-faq__trigger"
                >
                  <span>{q}</span>
                  <span className="s2-home-faq__toggle">{openFaq === i ? '−' : '+'}</span>
                </button>
                {openFaq === i && (
                  <div className="s2-home-faq__answer">{a}</div>
                )}
              </div>
            ))}
          </div>
          <div className="s2-home-faq__footer">
            <Link to="/faq" className="s2-home-text-link">
              {settings.faq_footer_link_text || 'View All FAQs →'}
            </Link>
          </div>
        </div>
      </section>
      )}

      {!sectionHidden(settings, 'hide_section_newsletter') && (
      <section
        className={`s2-home-band s2-home-newsletter s2-experience-section ${bandPadClass(settings, 'css_newsletter_padding')}`}
        data-s2-section="newsletter"
        data-home-section-id="newsletter"
        data-s2-reveal=""
        style={{ ...bandOrder('newsletter'), ...pickCssStyle(settings, { bg: 'css_newsletter_bg', padding: 'css_newsletter_padding' }) }}
      >
        <h3 className="s2-home-newsletter__title">{settings.newsletter_title || 'Subscribe to Our Newsletter'}</h3>
        <p className="s2-home-newsletter__sub">
          {settings.newsletter_subtitle || 'Stay updated on the latest NRI news, service launches, and important updates.'}
        </p>
        <div className="s2-home-newsletter__form">
          <input
            type="email"
            placeholder={settings.newsletter_placeholder || 'Your email address'}
            className="s2-home-newsletter__input"
            aria-label="Email for newsletter"
          />
          <button type="button" className="s2-home-newsletter__btn">
            {settings.newsletter_button || 'Subscribe'}
          </button>
        </div>
      </section>
      )}

      {!sectionHidden(settings, 'hide_section_app') && (
      <section
        className="s2-home-band s2-home-app s2-surface-dark s2-experience-section"
        data-s2-section="app"
        data-home-section-id="app"
        data-s2-reveal=""
        style={{ ...bandOrder('app'), ...pickCssStyle(settings, { bg: 'css_app_bg' }) }}
      >
        <div className="s2-home-app__grid s2-mobile-stack">
          <div>
            <p className="s2-home-app__eyebrow">{settings.app_eyebrow || 'Mobile App'}</p>
            <h3 className="s2-home-app__title">{settings.app_title || 'Download Our App'}</h3>
            <p className="s2-home-app__sub">
              {settings.app_subtitle ||
                'Manage all your NRI services from your smartphone anytime, anywhere. Track progress, upload documents, and communicate with our team on the go.'}
            </p>
            <div className="s2-home-app__stores">
              {[
                { href: settings.app_playstore_url || '#', icon: '▶', line1: 'GET IT ON', line2: 'Google Play' },
                { href: settings.app_appstore_url || '#', icon: '🍎', line1: 'DOWNLOAD ON THE', line2: 'App Store' },
              ].map(({ href, icon, line1, line2 }) => (
                <a key={line2} href={href} target="_blank" rel="noopener noreferrer" className="s2-home-app__store">
                  <span className="s2-home-app__store-icon">{icon}</span>
                  <div>
                    <div className="s2-home-app__store-line1">{line1}</div>
                    <div className="s2-home-app__store-line2">{line2}</div>
                  </div>
                </a>
              ))}
            </div>
          </div>
          <div className="s2-home-app__emoji" aria-hidden>📱</div>
        </div>
      </section>
      )}

      {!sectionHidden(settings, 'hide_section_locations') && (
      <div className="s2-home-band s2-home-locations" data-s2-section="locations" data-home-section-id="locations" style={bandOrder('locations')}>
        <p className="s2-home-logo-strip__label">{settings.home_locations_label || 'Locations'}</p>
        <div className="s2-home-locations__pills">
          {displayCities.map(({ name }) => (
            <Link key={name} to="/services/property" className="s2-home-loc-pill">
              📍 {name}
            </Link>
          ))}
        </div>
      </div>
      )}
      </div>
    </Layout>
  )
}
