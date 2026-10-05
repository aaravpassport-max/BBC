/**
 * Public pages — exact match of compiled components:
 *   Bn  → AboutPage
 *   Nn  → ContactPage
 *   $n  → HowItWorksPage
 *   Fn  → FAQPage
 *   Hn  → PricingPage
 *   Gn  → BlogListPage
 *   qn  → BlogDetailPage
 *   Vn  → TermsPage
 *   Yn  → PrivacyPage
 *   Un  → CityPage
 */

import React, { useState, useEffect, useCallback } from 'react'
import { resolvePrimary } from '@/lib/design-tokens'
import { Link, useParams } from 'react-router-dom'
import { Layout, PageHero } from '@/components/layout/Layout'
import { useStore } from '@/lib/store'
import { api } from '@/lib/api'
import { useResource } from '@/lib/useResource'
import { BlogArticleShellSkeleton } from '@/components/ui/LoadingPlaceholders'
import { getAvatarImage, IMAGES } from '@/lib/images'
import type { FAQ, BlogPost, PricingPlan } from '@/types'
import { PublicSection, PublicSectionHead, PublicGrid, PublicCard, PublicCtaLink } from '@/components/public/PublicLayout'
import {
  parseAboutHighlights,
  parseHiwSteps,
  parseHoursRows,
  parseStringListJson,
  parseTeamMembers,
  parseValueCards,
  parseWhyChooseCards,
} from '@/lib/home-content-settings'
import { parseHeroMetaJson } from '@/lib/page-hero-meta'
import { usePageDocumentMeta } from '@/lib/page-document-meta'
import { isTemplateSectionHidden } from '@/lib/section-visibility'
import { CmsElement } from '@/components/public/CmsElement'

// ── AboutPage (Bn) ────────────────────────────────────────────────────────────
export function AboutPage() {
  const settings = useStore((s) => s.settings)
  const name     = settings.platform_name || 'Services2NRI'
  const whatsapp = settings.platform_whatsapp

  const team = [
    { name: 'Arun Sharma',    role: 'Founder & CEO',           img: getAvatarImage('avatar_1'), bio: '15+ years in NRI services. Ex-Wall Street IT professional.' },
    { name: 'Priya Mehta',    role: 'Head of Operations',       img: getAvatarImage('avatar_3'), bio: '10+ years managing NRI documentation and legal processes.' },
    { name: 'Rahul Verma',    role: 'Lead Property Manager',    img: getAvatarImage('avatar_2'), bio: 'Certified property manager with pan-India network.' },
    { name: 'Deepa Krishnan', role: 'Financial Advisor',        img: getAvatarImage('avatar_5'), bio: 'CA with specialization in NRI taxation and investments.' },
  ]

  const valuesFallback = [
    { icon: '🔒', t: 'Trust & Transparency', d: 'Every quote is itemised. Every document is encrypted. No surprises.' },
    { icon: '⚡', t: 'Speed & Efficiency',   d: 'We know time is precious. Most services delivered in 7–21 days.' },
    { icon: '🌍', t: 'Global Reach',          d: 'Serving NRIs in 50+ countries with round-the-clock support.' },
    { icon: '💎', t: 'Premium Quality',       d: 'Verified professionals, legal compliance, quality guarantees.' },
  ]
  const values = parseValueCards(settings.about_values_json, valuesFallback)
  const teamDisplay = parseTeamMembers(settings.about_team_json, team)
  const highlights = parseAboutHighlights(settings.about_highlights_json, [
    { value: '10,000+', label: 'Clients Served' },
    { value: '44+', label: 'Services' },
    { value: '50+', label: 'Cities' },
  ])

  return (
    <Layout>
      <PageHero
        pageTemplateId="about"
        templateSectionKey="intro"
        title={settings.about_page_title || 'About Us'}
        subtitle={settings.about_page_subtitle || 'Trusted NRI service partner since 2015 — making India management effortless from anywhere in the world.'}
        meta={parseHeroMetaJson(settings.about_hero_meta_json, [
          { icon: '🌍', label: '50+ countries' },
          { icon: '👥', label: '10,000+ clients' },
          { icon: '🔒', label: 'Encrypted docs' },
        ])}
      />

      <PublicSection pageTemplateId="about" sectionKey="about" width="wide">
        <div className="s2-marketing-page s2-public-split-grid s2-mobile-stack">
          <div>
            <PublicSectionHead eyebrow={settings.about_eyebrow || 'Our Story'} title={settings.about_heading || `${name} — Your Bridge to India`} />
            <p className="s2-t-body s2-public-body-tight">
              {settings.about_text || `${name} was founded with a single mission: to eliminate the paperwork stress that NRIs face when managing affairs back home.`}
            </p>
            <p className="s2-t-body s2-public-body-tight s2-public-body-tight--lg">
              {settings.about_text_secondary ||
                'Our team of lawyers, CAs, property managers, and immigration specialists has helped over 10,000 NRIs across 50+ countries resolve their India-related needs without a single trip back home.'}
            </p>
            <PublicGrid min={120}>
              {highlights.map(({ value, label }) => (
                <PublicCard key={label} className="s2-public-icon-tile">
                  <div className="s2-text-primary s2-public-stat-val">{value}</div>
                  <div className="s2-text-muted s2-public-stat-lbl">{label}</div>
                </PublicCard>
              ))}
            </PublicGrid>
            <div className="s2-public-actions">
              <PublicCtaLink to="/services">Our Services →</PublicCtaLink>
              {whatsapp && <a href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer" className="s2-btn s2-btn--secondary s2-btn--sm">💬 Chat with Us</a>}
            </div>
          </div>
          <div className="s2-home-about__img-wrap">
            <img src={settings.about_image_url || IMAGES.about} alt={`About ${name}`} className="s2-home-about__img" />
            {settings.about_video_url && (
              <a href={settings.about_video_url} target="_blank" rel="noopener noreferrer" className="s2-home-about__video-link" aria-label="Watch video">
                <div className="s2-home-about__play"><span aria-hidden>▶</span></div>
              </a>
            )}
          </div>
        </div>
      </PublicSection>

      <PublicSection pageTemplateId="about" alt sectionKey="values">
        <PublicSectionHead title={settings.about_values_title || 'Our Core Values'} />
        <PublicGrid min={220}>
          {values.map(({ icon, t, d }) => (
            <PublicCard key={t} className="s2-public-icon-tile">
              <div className="s2-public-icon-tile__icon">{icon}</div>
              <h3 className="s2-public-card__title">{t}</h3>
              <p className="s2-public-card__body">{d}</p>
            </PublicCard>
          ))}
        </PublicGrid>
      </PublicSection>

      {!isTemplateSectionHidden(settings, 'about', 'team') && (
      <section className="s2-public-team-section s2-experience-section" data-s2-section="team" data-s2-reveal="">
        <div className="s2-container">
          <h2 className="s2-public-section-title s2-public-section-title--center">{settings.about_team_title || 'Meet Our Team'}</h2>
          <div className="s2-public-team-grid s2-stagger">
            {teamDisplay.map(({ name: n, role, img, bio }) => (
              <div key={n} className="s2-public-team-card">
                <img src={img} alt={n} />
                <div className="s2-public-team-card__body">
                  <h3 className="s2-public-team-card__name">{n}</h3>
                  <div className="s2-public-team-card__role">{role}</div>
                  <p className="s2-public-team-card__bio">{bio}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>
      )}

      {!isTemplateSectionHidden(settings, 'about', 'cta') && (
      <section className="s2-public-band-dark s2-surface-dark s2-marketing-page s2-experience-section" data-s2-section="cta" data-s2-reveal="">
        <h2 className="s2-public-band-dark__title">{settings.about_cta_title || 'Ready to Get Started?'}</h2>
        <p className="s2-public-band-dark__sub">{settings.about_cta_subtitle || 'Let us handle your India affairs while you focus on what matters.'}</p>
        <div className="s2-public-band-dark__actions">
          <Link to={settings.about_cta_primary_url || '/services'} className="s2-public-band-dark__btn-primary">
            {settings.about_cta_primary_text || 'Explore Services'}
          </Link>
          <Link to={settings.about_cta_secondary_url || '/register'} className="s2-public-band-dark__btn-ghost">
            {settings.about_cta_secondary_text || 'Create Free Account'}
          </Link>
        </div>
      </section>
      )}
    </Layout>
  )
}

// ── ContactPage (Nn) ──────────────────────────────────────────────────────────
export function ContactPage() {
  const settings = useStore((s) => s.settings)
  const primary  = resolvePrimary(settings)
  const whatsapp = settings.platform_whatsapp
  const displayEmail = settings.contact_email || settings.platform_email
  const displayPhone = settings.contact_phone || settings.platform_phone
  const displayAddress =
    settings.contact_address || settings.platform_address || settings.platform_city || 'Pune, Maharashtra, India'

  const [form,    setForm]    = useState({ name: '', email: '', phone: '', subject: '', message: '' })
  const [success, setSuccess] = useState(false)
  const [failed,  setFailed]  = useState(false)
  const [loading, setLoading] = useState(false)

  const handleSubmit = async () => {
    if (!form.name || !form.email || !form.message) return
    setLoading(true)
    setFailed(false)
    try {
      await api.post('contact', form)
      setSuccess(true)
    } catch {
      // FIXED: was previously "setSuccess(true) // Show success regardless
      // (per compiled behaviour)" — a real, significant ghost-success bug.
      // This form may be a customer's ONLY point of contact; claiming
      // "Message Sent!" when the request genuinely failed means the
      // customer believes they reached out successfully and simply waits
      // for a reply that will never come, with no indication anything
      // went wrong. The good intent behind the original behavior — always
      // giving the customer a working alternative (WhatsApp) — is
      // preserved below in the failure state; only the false claim of
      // success is removed.
      setFailed(true)
    }
    setLoading(false)
  }

  const hoursFallback = [
    { day: 'Mon – Fri', hours: '9:00 AM – 8:00 PM IST' },
    { day: 'Saturday', hours: '10:00 AM – 6:00 PM IST' },
    { day: 'Sunday', hours: 'Emergency Support Only' },
  ]
  const hoursRows = parseHoursRows(settings.contact_hours_json, hoursFallback)

  const contacts = [
    { icon: '💬', t: 'WhatsApp (Fastest)', v: whatsapp ? `https://wa.me/${String(whatsapp).replace(/\D/g, '')}` : null, label: whatsapp ? `+${String(whatsapp).replace(/\D/g, '')}` : null },
    { icon: '✉️', t: 'Email', v: displayEmail ? `mailto:${displayEmail}` : null, label: displayEmail },
    { icon: '📞', t: 'Phone', v: displayPhone ? `tel:${displayPhone}` : null, label: displayPhone },
    { icon: '📍', t: 'Address', v: null, label: displayAddress },
  ].filter((c) => c.label)

  return (
    <Layout>
      <PageHero
        pageTemplateId="contact"
        templateSectionKey="intro"
        title={settings.contact_page_title || settings.contact_title || 'Contact Us'}
        subtitle={settings.contact_page_subtitle || "We're here to help. Reach us via WhatsApp, email, or the form below."}
        primary={primary}
        meta={parseHeroMetaJson(settings.contact_hero_meta_json, [
          { icon: '💬', label: 'WhatsApp 30 min' },
          { icon: '📧', label: 'Reply in 24h' },
        ])}
      />
      <PublicSection pageTemplateId="contact" sectionKey="contact" width="wide">
        <div className="s2-public-contact-grid s2-mobile-stack">
          <div>
            <PublicSectionHead title={settings.contact_title || 'Get in Touch'} />
            {contacts.map(({ icon, t, v, label }) => (
              <div key={t} className="s2-public-contact-row">
                <div className="s2-public-contact-icon">{icon}</div>
                <div>
                  <div className="s2-t-eyebrow s2-public-stat-lbl">{t}</div>
                  {v ? <a href={v} target="_blank" rel="noopener noreferrer" className="s2-text-primary s2-public-contact-link">{label}</a>
                     : <span className="s2-t-body">{label}</span>}
                </div>
              </div>
            ))}
            <PublicCard className="s2-public-card--hours">
              <h3 className="s2-t-h3 s2-public-card__title--sm">{settings.contact_hours_title || 'Business Hours'}</h3>
              {hoursRows.map(({ day, hours }) => (
                <div key={day} className="s2-t-body s2-public-hours-row">
                  <span>{day}</span>
                  <span>{hours}</span>
                </div>
              ))}
            </PublicCard>
          </div>

          <div>
            {success ? (
              <div className="s2-public-form-msg">
                <div className="s2-public-form-msg__icon">✅</div>
                <h3 className="s2-text-success s2-public-form-msg__title">{settings.contact_success_title || 'Message Sent!'}</h3>
                <p className="s2-t-body">
                  {settings.contact_success_body ||
                    "We'll get back to you within 24 hours. You can also WhatsApp us for faster response."}
                </p>
                {whatsapp && <a href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer" className="s2-btn s2-btn--whatsapp s2-public-form-msg__wa">💬 WhatsApp Us</a>}
              </div>
            ) : failed ? (
              <div className="s2-public-form-msg">
                <div className="s2-public-form-msg__icon">⚠️</div>
                <h3 className="s2-public-form-msg__title s2-public-form-msg__title--error">
                  {settings.contact_failed_title || "Couldn't send your message"}
                </h3>
                <p className="s2-t-body">
                  {settings.contact_failed_body ||
                    'Something went wrong on our end. Please try again, or reach us directly on WhatsApp for a faster response.'}
                </p>
                {whatsapp && <a href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer" className="s2-btn s2-btn--whatsapp s2-public-form-msg__wa">💬 WhatsApp Us</a>}
                <div className="s2-public-form-msg__retry">
                  <button type="button" onClick={() => setFailed(false)} className="s2-btn s2-btn--ghost s2-btn--sm">← Try again</button>
                </div>
              </div>
            ) : (
              <div className="s2-public-contact-form">
                <h3 className="s2-t-h3">{settings.contact_form_title || 'Send Us a Message'}</h3>
                <div className="s2-public-form-grid">
                  {(
                    [
                      ['name', settings.contact_field_name_placeholder || 'Full Name', 'text'],
                      ['email', settings.contact_field_email_placeholder || 'Email Address', 'email'],
                      ['phone', settings.contact_field_phone_placeholder || 'Phone / WhatsApp', 'tel'],
                      ['subject', settings.contact_field_subject_placeholder || 'Subject', 'text'],
                    ] as const
                  ).map(([field, ph, type]) => (
                    <input key={field} type={type} placeholder={ph} value={form[field as keyof typeof form]} onChange={(e) => setForm((f) => ({ ...f, [field]: e.target.value }))}
                      className="s2-input" />
                  ))}
                </div>
                <textarea
                  rows={5}
                  placeholder={settings.contact_field_message_placeholder || 'Your message — describe what you need...'}
                  value={form.message}
                  onChange={(e) => setForm((f) => ({ ...f, message: e.target.value }))}
                  className="s2-textarea s2-wizard-textarea"
                />
                <button type="button" onClick={handleSubmit} disabled={loading} className="s2-btn s2-btn--primary s2-btn--block">
                  {loading ? 'Sending…' : settings.contact_form_submit_text || 'Send Message →'}
                </button>
              </div>
            )}
          </div>
        </div>
      </PublicSection>
    </Layout>
  )
}

// ── HowItWorksPage ($n) ───────────────────────────────────────────────────────
export function HowItWorksPage() {
  const settings = useStore((s) => s.settings)
  const primary = resolvePrimary(settings)

  const stepsFallback = [
    { n: 1, icon: '🔍', t: 'Browse & Select Service',    d: 'Visit our Services page and browse 44+ NRI services across 8 categories. Use the search bar or filter by category. Each service page shows the exact documents required, turnaround time, and a price range.' },
    { n: 2, icon: '📋', t: 'Submit Your Request',        d: 'Click "Place Service Inquiry" on the service page. Fill in the service-specific form (each service has a tailored form). You can also reach us directly on WhatsApp for a consultation first.' },
    { n: 3, icon: '📤', t: 'Upload Your Documents',      d: 'After submitting your request, you\'ll receive access to your secure dashboard. Upload your required documents through the encrypted portal. All files are AES-256 encrypted.' },
    { n: 4, icon: '✔️', t: 'Verification & Quote',       d: 'Our specialist team reviews your documents and application within 24 hours. You receive a detailed, itemised quote. No hidden charges — you see exactly what you are paying for.' },
    { n: 5, icon: '💳', t: 'Approve & Pay Securely',     d: 'Review the quote in your dashboard. Approve it and make payment via bank transfer, UPI, or Razorpay. For international clients, we accept SWIFT wire transfers.' },
    { n: 6, icon: '🚀', t: 'Receive Your Documents',     d: 'Our team handles the complete process with regular status updates. You track everything live in your dashboard. Documents are delivered to your overseas address or digitally as required.' },
  ]
  const steps = parseHiwSteps(settings.hiw_page_steps_json, stepsFallback)

  return (
    <Layout>
      <PageHero
        pageTemplateId="how-it-works"
        templateSectionKey="intro"
        title={settings.hiw_page_title || settings.hiw_title || 'How It Works'}
        subtitle={settings.hiw_page_subtitle || 'Get your NRI service done in 6 simple steps — from anywhere in the world.'}
        primary={primary}
        meta={parseHeroMetaJson(settings.hiw_hero_meta_json, [
          { icon: '📝', label: 'Submit online' },
          { icon: '💬', label: 'Quote in 24h' },
          { icon: '✅', label: 'Pay after approval' },
        ])}
      />
      {!isTemplateSectionHidden(settings, 'how-it-works', 'process') && (
      <section className="s2-public-how-section s2-marketing-page s2-experience-section" data-s2-section="process" data-s2-reveal="">
        <div className="s2-container s2-width-wide s2-stagger">
          {steps.map(({ n, icon, t, d }, i) => (
            <div key={n} className="s2-public-how-step">
              <div className="s2-public-how-step__rail">
                <div className="s2-public-how-step__icon">{icon}</div>
                {i < steps.length - 1 && <div className="s2-public-how-step__line" />}
              </div>
              <div className="s2-public-how-step__body">
                <div className="s2-public-how-step__eyebrow">Step {n}</div>
                <h3 className="s2-public-how-step__title">{t}</h3>
                <p className="s2-public-how-step__desc">{d}</p>
              </div>
            </div>
          ))}

          {!isTemplateSectionHidden(settings, 'how-it-works', 'hiw_cta') && (
          <div className="s2-public-how-callout">
            <h3 className="s2-public-how-callout__title">{settings.hiw_page_cta_title || 'Have Questions? Talk to Us First.'}</h3>
            <p className="s2-public-how-callout__sub">{settings.hiw_page_cta_subtitle || 'Our team is available 24/7 on WhatsApp for a free consultation before you place a request.'}</p>
            <div className="s2-public-how-callout__actions">
              <Link to="/services" className="s2-btn s2-btn--primary">Browse Services →</Link>
              <Link to="/contact" className="s2-home-text-link">Contact Us</Link>
            </div>
          </div>
          )}
        </div>
      </section>
      )}
    </Layout>
  )
}

// ── FAQPage (Fn) ──────────────────────────────────────────────────────────────
const FAQ_FALLBACK: FAQ[] = [
  { id: 1, q: 'What kind of services do you provide?',        a: 'We provide 44+ services across 8 categories — documentation, education, OCI/passport/visa, USCIS, property management, financial services, legal services, and taxation for NRIs worldwide.' },
  { id: 2, q: 'How long does each service typically take?',   a: 'Turnaround times vary by service. Simple certificates (birth, NABC) take 7–15 days. Transcripts and attestations take 15–30 days. Property services are ongoing. OCI card services take 45–90 days.' },
  { id: 3, q: 'Are my documents and personal data secure?',   a: 'Yes. All documents are stored with AES-256 end-to-end encryption. We maintain strict PII confidentiality and never share documents with third parties. Documents are deleted after service completion.' },
  { id: 4, q: 'What payment methods do you accept?',          a: 'We accept bank transfer (NEFT/RTGS/UPI from India), SWIFT wire transfer (for overseas clients), and online payment via Razorpay (credit/debit cards, UPI, net banking).' },
  { id: 5, q: 'Can I track my service request status?',      a: 'Yes. Once you submit a request, you get access to your personal dashboard where you can track every step in real time — from submission to document delivery.' },
  { id: 6, q: 'Do I need to travel to India for any of these services?', a: 'No. All our services are designed to be completed remotely. You submit documents digitally, we handle everything in India, and deliver results to your overseas address.' },
  { id: 7, q: 'What is your refund policy?',                 a: 'If we are unable to deliver the requested service for any reason within our control, we provide a full refund. If you cancel after the process has started, a partial refund is provided based on work completed.' },
  { id: 8, q: 'How do I contact you for urgent matters?',    a: 'The fastest way to reach us is via WhatsApp. We respond within 30 minutes during business hours (9 AM – 8 PM IST, Mon–Sat) and within 2 hours on Sundays and public holidays.' },
]

export function FAQPage() {
  const settings = useStore((s) => s.settings)
  const primary = resolvePrimary(settings)
  const [openIdx, setOpenIdx] = useState<number | null>(null)

  const { data } = useResource(
    'faqs',
    () => api.get<{ faqs: FAQ[] }>('faqs'),
    { persist: true, ttl: 120_000 },
  )

  const display = (data?.faqs?.length ? data.faqs : FAQ_FALLBACK)

  return (
    <Layout>
      <PageHero
        pageTemplateId="faq"
        templateSectionKey="intro"
        title={settings.faq_page_title || 'Frequently Asked Questions'}
        subtitle={settings.faq_page_subtitle || 'Everything you need to know before placing a service request.'}
        primary={primary}
        meta={parseHeroMetaJson(settings.faq_hero_meta_json, [
          { icon: '⚡', label: 'Fast responses' },
          { icon: '🛡️', label: 'Transparent process' },
        ])}
      />
      <PublicSection pageTemplateId="faq" className="s2-public-faq" sectionKey="faq" width="narrow">
        <div className="s2-container s2-width-narrow">
          {display.map((faq, i) => (
            <div key={faq.id || i} className={`s2-public-faq-item${openIdx === i ? ' s2-public-faq-item--open' : ''}`}>
              <button
                type="button"
                className="s2-public-faq-q s2-t-body"
                onClick={() => setOpenIdx(openIdx === i ? null : i)}
                aria-expanded={openIdx === i}
              >
                <span>{faq.q || faq.question}</span>
                <span className="s2-public-faq-toggle" aria-hidden>{openIdx === i ? '−' : '+'}</span>
              </button>
              {openIdx === i && (
                <div className="s2-public-faq-a">{faq.a || faq.answer}</div>
              )}
            </div>
          ))}

          {!isTemplateSectionHidden(settings, 'faq', 'faq_cta') && (
          <PublicCard className="s2-public-cta-band s2-public-cta-band--spaced">
            <h3 className="s2-t-h3">{settings.faq_cta_title || 'Still have questions?'}</h3>
            <p className="s2-t-body">
              {settings.faq_cta_body || 'Our team responds within 30 minutes on WhatsApp during business hours.'}
            </p>
            <PublicCtaLink to="/contact">{settings.faq_cta_button || 'Contact Us →'}</PublicCtaLink>
          </PublicCard>
          )}
        </div>
      </PublicSection>
    </Layout>
  )
}

// ── PricingPage (Hn) ──────────────────────────────────────────────────────────
const PRICING_FALLBACK: PricingPlan[] = [
  { id: 1, name: 'Starter',  subtitle: 'Property Valuation',     price: 'Free',              price_note: '',           popular: false, color: '#374151', features: ['Property Listing', 'Property Inspection Report', 'Basic Condition Assessment', 'Customer Support', 'Dedicated Account Manager'] },
  { id: 2, name: 'Basic',    subtitle: 'Documentation',          price: '₹5,000',            price_note: 'onwards',    popular: false, color: '#4A6FA5', features: ['Everything in Starter', 'Rental Agreement Drafting', 'Police Verification Assistance', 'Society NOC Coordination', 'Tenant Document Verification', 'Digital Document Storage'] },
  { id: 3, name: 'Advanced', subtitle: 'Tenancy Management',     price: '0.75 Month Rent',   price_note: 'one-time',   popular: true,  color: '#7b1fa2', features: ['Everything in Basic', 'Property Staging Support', 'Tenant Sourcing & Screening', 'Move-in Coordination', 'NRIWAY Owner Dashboard', 'Quarterly Inspection Reports', 'Service Request Portal'] },
  { id: 4, name: 'Pro',      subtitle: 'Comprehensive Management',price: '1 Month Rent',     price_note: 'per year',   popular: false, color: '#1E2D40', features: ['Everything in Advanced', '24/7 Customer Support', 'Legal Assistance', 'Advanced Tenant Screening', 'Emergency Assistance', 'Maintenance Supervision', 'Tenant Move-Out Report', 'Annual Property Valuation'] },
]

const COMPARE_FEATURES = [
  { feature: 'Dedicated Property Manager', plans: [false, true, true, true] },
  { feature: 'Property Listing',           plans: [true,  true, true, true] },
  { feature: 'Property Inspection',        plans: [true,  true, true, true] },
  { feature: 'Rental Agreement',           plans: [false, true, true, true] },
  { feature: 'Tenant Sourcing',            plans: [false, false, true, true] },
  { feature: 'Owner Dashboard',            plans: [false, false, true, true] },
  { feature: '24/7 Support',               plans: [false, false, false, true] },
  { feature: 'Legal Assistance',           plans: [false, false, false, true] },
]

export function PricingPage() {
  const settings = useStore((s) => s.settings)

  const { data } = useResource(
    'pricing-plans',
    () => api.get<{ plans: PricingPlan[] }>('pricing-plans'),
    { persist: true, ttl: 120_000 },
  )

  const display = (data?.plans?.length ? data.plans : PRICING_FALLBACK)

  const heroBg = 'linear-gradient(135deg, var(--s2-color-secondary) 0%, var(--s2-color-primary) 100%)'

  return (
    <Layout>
      <PageHero
        pageTemplateId="pricing"
        templateSectionKey="intro"
        title={settings.pricing_page_title || 'The Perfect Balance of Features & Affordability'}
        subtitle={settings.pricing_page_subtitle || 'Transparent pricing, no hidden charges. Pay only after approving your quote.'}
        bg={heroBg}
      />
      <PublicSection pageTemplateId="pricing" alt sectionKey="pricing" width="standard">
        <PublicSectionHead
          eyebrow={settings.pricing_grid_eyebrow || 'Pricing plans'}
          title={settings.pricing_grid_title || 'Choose the right level of support'}
          subtitle={settings.pricing_grid_subtitle || 'Compliance-driven · Simple & intuitive · Straightforward pricing'}
        />
        <div className="s2-container">
          <div className="s2-public-pricing-grid s2-stagger">
            {display.map((plan) => (
              <div
                key={plan.id}
                className={`s2-public-pricing-card${plan.popular ? ' s2-public-pricing-card--popular' : ''}`}
              >
                {plan.popular && <div className="s2-public-pricing-card__ribbon">⭐ MOST POPULAR</div>}
                <div
                  className={`s2-public-pricing-card__head${plan.popular ? ' s2-public-pricing-card__head--popular' : ''}`}
                  style={plan.popular ? undefined : { background: plan.color || 'var(--s2-color-secondary)' }}
                >
                  <div className="s2-t-eyebrow s2-public-pricing-head-eyebrow">{plan.subtitle}</div>
                  <h3 className="s2-t-h2 s2-public-pricing-head-title">{plan.name}</h3>
                  <div className="s2-t-h1 s2-public-pricing-head-price">{plan.price}</div>
                  {plan.price_note && <div className="s2-t-body s2-public-pricing-head-note">{plan.price_note}</div>}
                </div>
                <div className="s2-public-pricing-card__features">
                  {(plan.features || []).map((f, i) => (
                    <div key={i} className="s2-public-pricing-feature">
                      <mark>✓</mark>
                      <span>{f}</span>
                    </div>
                  ))}
                  <Link
                    to="/contact"
                    className={`s2-btn s2-btn--${plan.popular ? 'primary' : 'outline'} s2-public-pricing-cta`}
                  >
                    {plan.price === 'Free' ? 'Get Started Free' : 'Choose Plan →'}
                  </Link>
                </div>
              </div>
            ))}
          </div>
        </div>
      </PublicSection>

      {/* Comparison table */}
      {/* FIXED: this section previously always rendered, but
          COMPARE_FEATURES' checkmark data is hardcoded to match the 4
          specific fallback plans (Starter/Basic/Advanced/Pro) by
          position — it has no relationship to whatever real plans an
          admin configures via AdminPricing. Since PricingPlan.features
          is just a flat string list (no structured per-feature boolean
          data), there is no way to derive a real, accurate comparison
          from actual admin-managed plans. Showing this table against
          real plans would display misaligned or flatly incorrect
          feature availability — a real, customer-facing accuracy
          problem, not just a cosmetic one. Only shown when displaying
          the fallback content it was actually authored for. */}
      {(data?.plans?.length ?? 0) === 0 && (
        <PublicSection pageTemplateId="pricing" sectionKey="compare" width="wide">
          <PublicSectionHead
            title={settings.pricing_compare_title || 'Services2NRI vs Others'}
            subtitle={settings.pricing_compare_subtitle || 'See why NRIs choose us over traditional property managers'}
          />
          <div className="s2-public-compare-wrap s2-container s2-width-wide">
            <table className="s2-public-compare-table">
              <thead>
                <tr>
                  <th>Feature</th>
                  {display.map((p) => (
                    <th key={p.id} className={p.popular ? 's2-public-compare-th-popular' : undefined}>{p.name}</th>
                  ))}
                  <th className="s2-public-compare-th-danger">Others</th>
                </tr>
              </thead>
              <tbody>
                {COMPARE_FEATURES.map(({ feature, plans: vals }) => (
                  <tr key={feature}>
                    <td>{feature}</td>
                    {vals.map((v, j) => (
                      <td key={j} className="s2-public-compare-td-center">{v ? '✓' : '—'}</td>
                    ))}
                    <td className="s2-public-compare-td-center">✕</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </PublicSection>
      )}

      {!isTemplateSectionHidden(settings, 'pricing', 'consultation') && (
      <PublicSection pageTemplateId="pricing" sectionKey="consultation" alt className="s2-public-cta-band">
        <div className="s2-public-center-copy">
          <h2 className="s2-t-h2">{settings.pricing_consultation_title || 'Book a Free Consultation'}</h2>
          <p className="s2-t-body">
            {settings.pricing_consultation_subtitle || 'Not sure which plan is right for you? Talk to our team for free.'}
          </p>
          <PublicCtaLink to={settings.pricing_consultation_url || '/contact'}>
            {settings.pricing_consultation_button || 'Book Free Consultation →'}
          </PublicCtaLink>
        </div>
      </PublicSection>
      )}

      <PublicSection pageTemplateId="pricing" sectionKey="compare">
        <PublicSectionHead
          title={settings.pricing_compare_brand_title || 'NRIWAY vs. Traditional Agents'}
          subtitle={settings.pricing_compare_brand_subtitle || 'See why NRIs across 50+ countries trust NRIWAY'}
        />
        <div className="s2-public-compare-wrap s2-container s2-width-narrow">
          <table className="s2-public-compare-table">
            <thead>
              <tr>
                <th className="s2-public-compare-table__feature">Feature</th>
                <th className="s2-public-compare-table__brand">{settings.pricing_compare_brand_name || settings.platform_name || 'NRIWAY'}</th>
                <th>{settings.pricing_compare_other_name || 'Traditional Agents'}</th>
              </tr>
            </thead>
            <tbody>
              {[
                ['Real-time booking tracking', '✅ Dashboard + email updates', '❌ Phone calls only'],
                ['Transparent pricing', '✅ Quote before any payment', '❌ Hidden charges common'],
                ['Document security', '✅ AES-256 encrypted portal', '❌ Via WhatsApp / email'],
                ['Pan India coverage', '✅ 750+ cities across India', '⚠️ Limited to few cities'],
                ['Money-back guarantee', '✅ On failed applications', '❌ Non-refundable deposits'],
                ['24/7 WhatsApp support', '✅ Instant response team', '⚠️ Office hours only'],
                ['No upfront fee for quote', '✅ Free consultation always', '❌ Retainer required'],
              ].map(([feat, us, them]) => (
                <tr key={feat}>
                  <td className="s2-public-compare-feat">{feat}</td>
                  <td className="s2-public-compare-td-success">{us}</td>
                  <td className="s2-public-compare-td-muted">{them}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </PublicSection>

    </Layout>
  )
}

// ── BlogListPage (Gn) — Premium redesign ──────────────────────────────────────
const BLOG_FALLBACK: BlogPost[] = [
  { id: 1, slug: 'oci-card-renewal-guide',       title: 'Complete Guide to OCI Card Renewal in 2025',                    excerpt: 'Everything you need to know about renewing your OCI card — documents, process, timeline, and costs explained.', category: 'Immigration', date: 'Jan 15, 2025', img: IMAGES.apostille, read_time: '7 min read' },
  { id: 2, slug: 'nri-property-management-tips', title: '5 Things Every NRI Should Know Before Renting Their Property',  excerpt: 'Avoid the most common mistakes NRIs make when renting their India property. Expert advice from our team.',        category: 'Property',    date: 'Feb 3, 2025',  img: IMAGES.property,  read_time: '5 min read' },
  { id: 3, slug: 'nri-itr-filing-guide',         title: 'NRI Income Tax Return Filing: Complete 2024-25 Guide',          excerpt: 'A complete guide to filing ITR as an NRI — what income is taxable, deadlines, and how to avoid penalties.',     category: 'Taxation',    date: 'Mar 10, 2025', img: IMAGES.tax,       read_time: '9 min read' },
  { id: 4, slug: 'apostille-attestation-guide',  title: 'Apostille vs Attestation — Which Do You Need?',                 excerpt: 'Confused about apostille and attestation? This guide explains the difference, when you need each, and costs.', category: 'Documentation', date: 'Apr 5, 2025', img: IMAGES.apostille, read_time: '6 min read' },
  { id: 5, slug: 'oci-card-benefits-2025',       title: 'OCI Card Benefits in 2025 — Complete Rights & Privileges Guide', excerpt: 'Full list of OCI cardholder rights, visa-free entry rules, restrictions, and how to maximise your OCI benefits.', category: 'Immigration', date: 'May 1, 2025', img: IMAGES.about,     read_time: '8 min read' },
  { id: 6, slug: 'nri-bank-account-guide',       title: 'NRE vs NRO vs FCNR — Which Bank Account Do NRIs Need?',        excerpt: 'Understand the key differences between NRE, NRO, and FCNR bank accounts and which one suits your NRI profile.', category: 'Banking',     date: 'Jun 2, 2025', img: IMAGES.tax,       read_time: '6 min read' },
]

const BLOG_CATEGORIES = ['All', 'Immigration', 'Property', 'Taxation', 'Documentation', 'Banking', 'Legal', 'Education']

function estimateReadTime(content?: string): string {
  if (!content) return '3 min read'
  const words = content.replace(/<[^>]+>/g, ' ').split(/\s+/).filter(Boolean).length
  return `${Math.max(1, Math.ceil(words / 200))} min read`
}

export function BlogListPage() {
  const settings = useStore((s) => s.settings)
  const siteName = settings.platform_name || 'Services2NRI'
  usePageDocumentMeta(settings, {
    titleKey: 'seo_title',
    descriptionKey: 'seo_description',
    titleFallback: `${siteName} — NRI Knowledge Hub`,
    descriptionFallback: settings.blog_hero_subtitle,
  })
  const [search,     setSearch]     = useState('')
  const [activeCategory, setActiveCategory] = useState('All')
  const blogCategories = parseStringListJson(settings.blog_categories_json, BLOG_CATEGORIES)

  const { data } = useResource(
    'blog?per_page=20',
    () => api.get<{ posts: BlogPost[] }>('blog?per_page=20'),
    { persist: true, ttl: 120_000 },
  )

  const posts = data?.posts ?? []
  const allPosts = posts.length > 0 ? posts : BLOG_FALLBACK
  const featured = allPosts[0]
  const rest = allPosts.slice(1).filter(p => {
    const matchCat = activeCategory === 'All' || p.category === activeCategory
    const matchSearch = !search || p.title.toLowerCase().includes(search.toLowerCase()) || (p.excerpt || '').toLowerCase().includes(search.toLowerCase())
    return matchCat && matchSearch
  })

  const hideHero = isTemplateSectionHidden(settings, 'blog', 'hero')
  const hideFeed = isTemplateSectionHidden(settings, 'blog', 'feed')

  return (
    <Layout>
      {!hideHero && (
      <div className="s2-marketing-page s2-blog-hero s2-surface-dark" data-s2-section="hero">
        <div className="s2-container">
          <CmsElement pageId="blog" sectionKey="hero" elementId="eyebrow" as="p" className="s2-blog-hero__eyebrow">
            {settings.blog_hero_eyebrow || 'NRI Knowledge Hub'}
          </CmsElement>
          <CmsElement pageId="blog" sectionKey="hero" elementId="heading" as="h1" className="s2-blog-hero__title">
            {settings.blog_hero_title || 'Expert Guides for NRIs Living Abroad'}
          </CmsElement>
          <CmsElement pageId="blog" sectionKey="hero" elementId="subtitle" as="p" className="s2-blog-hero__sub">
            {settings.blog_hero_subtitle ||
              `Real advice on OCI cards, property management, taxation, and more — written by ${siteName} specialists.`}
          </CmsElement>
          <div className="s2-blog-search">
            <input
              type="search"
              value={search}
              onChange={e => setSearch(e.target.value)}
              placeholder={settings.blog_search_placeholder || 'Search articles…'}
              aria-label="Search articles"
            />
            <div className="s2-blog-search__icon" aria-hidden>🔍</div>
          </div>
        </div>
      </div>
      )}

      {!hideFeed && (
      <>
      <div className="s2-blog-filters" data-s2-section="feed">
        <div className="s2-container s2-blog-filters__row">
          {blogCategories.map(cat => (
            <button
              key={cat}
              type="button"
              onClick={() => setActiveCategory(cat)}
              className={`s2-blog-chip${activeCategory === cat ? ' s2-blog-chip--active' : ''}`}
            >
              {cat}
            </button>
          ))}
        </div>
      </div>

      <div className="s2-container s2-blog-main">
        <>
            {featured && activeCategory === 'All' && !search && (
              <Link to={`/blog/${featured.slug}`} className="s2-blog-featured s2-mobile-stack">
                <img src={featured.img || featured.image_url || IMAGES.about} alt={featured.title} />
                <div className="s2-blog-featured__body">
                  <div className="s2-blog-featured__meta">
                    <span className="s2-blog-badge s2-blog-badge--featured">Featured</span>
                    <span className="s2-blog-featured__cat">{featured.category}</span>
                  </div>
                  <h2 className="s2-blog-featured__title">{featured.title}</h2>
                  <p className="s2-blog-featured__excerpt">{featured.excerpt}</p>
                  <div className="s2-blog-featured__foot">
                    <span>📅 {featured.date || featured.created_at?.split(' ')[0]}</span>
                    <span>⏱ {featured.read_time || estimateReadTime(featured.content)}</span>
                  </div>
                  <div className="s2-blog-featured__cta">Read Article →</div>
                </div>
              </Link>
            )}

            <div className="s2-blog-grid">
              {rest.map(post => (
                <Link key={post.id} to={`/blog/${post.slug}`} className="s2-blog-card">
                  <div className="s2-blog-card__img-wrap">
                    <img src={post.img || post.image_url || IMAGES.about} alt={post.title} />
                    <div className="s2-blog-card__cat">{post.category}</div>
                  </div>
                  <div className="s2-blog-card__body">
                    <h3 className="s2-blog-card__title">{post.title}</h3>
                    <p className="s2-blog-card__excerpt">{post.excerpt}</p>
                    <div className="s2-blog-card__foot">
                      <div className="s2-blog-card__meta">
                        <span>📅 {post.date || post.created_at?.split(' ')[0]}</span>
                        <span>⏱ {post.read_time || estimateReadTime(post.content)}</span>
                      </div>
                      <span className="s2-blog-card__read">Read →</span>
                    </div>
                  </div>
                </Link>
              ))}
            </div>

            {rest.length === 0 && (
              <div className="s2-blog-empty">
                <div className="s2-blog-empty__icon">🔍</div>
                <h3 className="s2-blog-empty__title">No articles found</h3>
                <p className="s2-t-body">Try a different search term or category.</p>
                <button type="button" onClick={() => { setSearch(''); setActiveCategory('All') }} className="s2-btn s2-btn--primary s2-blog-empty__btn">Clear Filters</button>
              </div>
            )}
        </>
      </div>
      </>
      )}
    </Layout>
  )
}

// ── BlogDetailPage (qn) — Premium redesign ────────────────────────────────────
const BLOG_DETAIL_FALLBACK: Record<string, BlogPost> = {
  'oci-card-renewal-guide': {
    id: 1, slug: 'oci-card-renewal-guide', category: 'Immigration', title: 'Complete Guide to OCI Card Renewal in 2025', created_at: '2025-01-15 00:00:00',
    content: '<p>Renewing your OCI (Overseas Citizen of India) card is mandatory when you renew your foreign passport.</p><h2>Who Needs to Renew?</h2><p>Every OCI cardholder must renew their OCI card each time they get a new passport up to the age of 20, and once after turning 50.</p><h2>Documents Required</h2><ul><li>Current valid foreign passport</li><li>Old OCI card</li><li>Two passport-size photographs</li></ul><h2>Timeline &amp; Costs</h2><p>Standard processing takes 4–8 weeks. Contact Services2NRI for a complete quote and full assistance.</p>',
    read_time: '7 min read',
  },
}

export function BlogDetailPage() {
  const { slug }  = useParams<{ slug: string }>()
  const settings  = useStore((s) => s.settings)
  const siteName  = settings.platform_name || 'Services2NRI'
  usePageDocumentMeta(settings, {
    titleKey: 'seo_title',
    descriptionKey: 'seo_description',
    titleFallback: siteName,
  })
  const postPath = slug ? `blog/${slug}` : null
  const [notFound, setNotFound] = useState(false)

  useEffect(() => {
    setNotFound(false)
  }, [slug])

  const fetchPost = useCallback(async () => {
    try {
      return await api.get<{ post: BlogPost }>(`blog/${slug!}`)
    } catch (e: unknown) {
      if ((e as { status?: number }).status === 404) setNotFound(true)
      throw e
    }
  }, [slug])

  const {
    data: postData,
    error: postError,
    isInitialLoad: postInitial,
    refresh: refreshPost,
  } = useResource(
    postPath,
    fetchPost,
    { persist: true, ttl: 120_000, enabled: !!slug },
  )

  const { data: relatedData } = useResource(
    'blog?per_page=4',
    () => api.get<{ posts: BlogPost[] }>('blog?per_page=4'),
    { persist: true, ttl: 120_000 },
  )

  const post = postData?.post ?? null
  const related = (relatedData?.posts || []).filter((p) => p.slug !== slug).slice(0, 3)
  const display = post || (slug ? BLOG_DETAIL_FALLBACK[slug] : null)
  const readTime = display?.read_time || estimateReadTime(display?.content)
  const loadFailed = postError && !display && !notFound

  const loadPost = useCallback(() => {
    setNotFound(false)
    refreshPost(true)
  }, [refreshPost])

  if (postInitial && !display) {
    return (
      <Layout>
        <BlogArticleShellSkeleton />
      </Layout>
    )
  }

  if (notFound && !display) {
    return (
      <Layout>
        <div className="s2-blog-state">
          <div className="s2-blog-state__icon">📄</div>
          <h2 className="s2-t-h2">Article not found</h2>
          <Link to="/blog" className="s2-home-text-link">← Back to Knowledge Hub</Link>
        </div>
      </Layout>
    )
  }

  if (loadFailed) {
    return (
      <Layout>
        <div className="s2-blog-state">
          <div className="s2-blog-state__icon">⚠️</div>
          <h2 className="s2-t-h2">Couldn't load this article</h2>
          <p className="s2-t-body">Something went wrong on our end. Please try again.</p>
          <button type="button" onClick={loadPost} className="s2-btn s2-btn--primary">Retry</button>
        </div>
      </Layout>
    )
  }

  if (!display) {
    return (
      <Layout>
        <div className="s2-blog-state">
          <div className="s2-blog-state__icon">📄</div>
          <h2 className="s2-t-h2">Article not found</h2>
          <Link to="/blog" className="s2-home-text-link">← Back to Knowledge Hub</Link>
        </div>
      </Layout>
    )
  }

  // Extract H2/H3 headings for sticky table of contents
  const headings: { id: string; text: string; level: number }[] = []
  const contentWithIds = (display.content || '').replace(/<(h[23])[^>]*>(.+?)<\/h[23]>/gi, (_, tag, text) => {
    const id = text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
    headings.push({ id, text: text.replace(/<[^>]+>/g, ''), level: tag === 'h2' ? 2 : 3 })
    return `<${tag} id="${id}">${text}</${tag}>`
  })

  return (
    <Layout>
      <div className="s2-marketing-page s2-blog-article-hero s2-surface-dark">
        <div className="s2-container s2-width-narrow">
          <Link to="/blog" className="s2-blog-article-hero__back">← Knowledge Hub</Link>
          <div className="s2-blog-article-hero__meta">
            <span className="s2-blog-article-hero__cat">{display.category}</span>
            <span className="s2-blog-article-hero__date">📅 {display.date || display.created_at?.split(' ')[0]}</span>
            <span className="s2-blog-article-hero__date">⏱ {readTime}</span>
          </div>
          <h1 className="s2-blog-article-hero__title">{display.title}</h1>
          {display.excerpt && <p className="s2-blog-article-hero__excerpt">{display.excerpt}</p>}
        </div>
      </div>

      {display.image_url && (
        <div className="s2-blog-hero-image">
          <img src={display.image_url} alt={display.title} />
        </div>
      )}

      <div className={`s2-container s2-blog-layout s2-mobile-stack${headings.length > 2 ? ' s2-blog-layout--toc' : ''}`}>
        <article className="s2-blog-prose">
          <div dangerouslySetInnerHTML={{ __html: contentWithIds || '<p>Content coming soon.</p>' }} />

          <div className="s2-blog-share">
            <p className="s2-blog-share__label">Share this article</p>
            <div className="s2-blog-share__row">
              {[
                { label: '🐦 Twitter/X', url: `https://twitter.com/intent/tweet?text=${encodeURIComponent(display.title)}&url=${encodeURIComponent(window.location.href)}` },
                { label: '💼 LinkedIn', url: `https://www.linkedin.com/shareArticle?mini=true&url=${encodeURIComponent(window.location.href)}&title=${encodeURIComponent(display.title)}` },
                { label: '💬 WhatsApp', url: `https://wa.me/?text=${encodeURIComponent(display.title + ' ' + window.location.href)}` },
              ].map(s => (
                <a key={s.label} href={s.url} target="_blank" rel="noopener noreferrer" className="s2-blog-share__link">
                  {s.label}
                </a>
              ))}
            </div>
          </div>

          <div className="s2-blog-inline-cta">
            <div>
              <h3 className="s2-blog-inline-cta__title">Need Help With {display.category} Services?</h3>
              <p className="s2-blog-inline-cta__sub">{siteName} experts handle everything. Get a free quote today.</p>
            </div>
            <Link to="/services" className="s2-blog-inline-cta__btn">Explore Services →</Link>
          </div>

          {related.length > 0 && (
            <div className="s2-blog-related">
              <h2 className="s2-blog-related__title">Related Articles</h2>
              <div className="s2-blog-related-grid">
                {related.map(r => (
                  <Link key={r.id} to={`/blog/${r.slug}`} className="s2-blog-related-card">
                    <img src={r.img || r.image_url || IMAGES.about} alt={r.title} />
                    <div className="s2-blog-related-card__body">
                      <div className="s2-blog-related-card__cat">{r.category}</div>
                      <h4 className="s2-blog-related-card__title">{r.title}</h4>
                    </div>
                  </Link>
                ))}
              </div>
            </div>
          )}
        </article>

        {headings.length > 2 && (
          <aside className="s2-blog-toc">
            <div className="s2-blog-toc__label">Table of Contents</div>
            {headings.map(h => (
              <a
                key={h.id}
                href={`#${h.id}`}
                className={`s2-blog-toc__link${h.level === 3 ? ' s2-blog-toc__link--h3' : ''}`}
                onClick={e => { e.preventDefault(); document.getElementById(h.id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }}
              >
                {h.level === 3 && <span className="s2-blog-toc__prefix">↳</span>}{h.text}
              </a>
            ))}
          </aside>
        )}
      </div>
    </Layout>
  )
}

// ── TermsPage (Vn) ────────────────────────────────────────────────────────────
function LegalCustomCss({ css }: { css?: string }) {
  if (!css?.trim()) return null
  return <style dangerouslySetInnerHTML={{ __html: css }} data-s2-legal-custom-css="" />
}

export function TermsPage() {
  const settings = useStore((s) => s.settings)
  const primary  = resolvePrimary(settings)
  const name     = settings.platform_name || 'Services2NRI'
  usePageDocumentMeta(settings, { titleKey: 'seo_title', descriptionKey: 'seo_description', titleFallback: `Terms & Conditions — ${name}` })

  const sections = [
    ['1. Acceptance of Terms',     `By accessing or using ${name}'s services, you agree to be bound by these Terms and Conditions.`],
    ['2. Services Description',    `${name} provides NRI-focused services including document procurement, property management, immigration assistance, financial services, and legal support in India.`],
    ['3. User Responsibilities',   'You are responsible for providing accurate information, maintaining confidentiality of your account credentials, and ensuring all uploaded documents are authentic.'],
    ['4. Payment Terms',           'Services are quote-based. You will receive an itemised quote before any payment is required. Payment constitutes acceptance of the quote.'],
    ['5. Confidentiality',         'We maintain strict confidentiality of all client documents and personal information. Documents are stored with AES-256 encryption and deleted after service completion.'],
    ['6. Limitation of Liability', `${name} is not liable for delays caused by government agencies, third parties, or circumstances beyond our control. Our liability is limited to the service fee paid.`],
    ['7. Intellectual Property',   `All content on this platform including text, graphics, and software is the intellectual property of ${name} and may not be reproduced without prior written consent.`],
    ['8. Governing Law',           'These terms are governed by the laws of India. Any disputes shall be subject to the jurisdiction of courts in Pune, Maharashtra.'],
  ]

  return (
    <Layout>
      <LegalCustomCss css={settings.custom_css_global} />
      <PageHero title="Terms & Conditions" subtitle="Last updated: January 2025" primary={primary} />
      <div className="s2-legal-page">
        {sections.map(([title, body]) => (
          <div key={title} className="s2-legal-section">
            <h2 className="s2-legal-section__title">{title}</h2>
            <p className="s2-legal-section__body">{body}</p>
          </div>
        ))}
      </div>
    </Layout>
  )
}

// ── PrivacyPage (Yn) ──────────────────────────────────────────────────────────
export function PrivacyPage() {
  const settings = useStore((s) => s.settings)
  const name     = settings.platform_name || 'Services2NRI'
  usePageDocumentMeta(settings, { titleKey: 'seo_title', descriptionKey: 'seo_description', titleFallback: `Privacy Policy — ${name}` })

  const sections = [
    { title: '1. Information We Collect', body: `When you use ${name}, we collect: personal information you provide (name, email, phone, government ID); account information; transaction data; document uploads (AES-256 encrypted); and usage data (IP, browser, pages visited) for analytics.` },
    { title: '2. How We Use Your Information', body: 'We use your information to: process service requests; create and manage your account; send transactional emails; process payments securely; improve our services through anonymised analytics; and comply with legal obligations. We do not use your personal data for advertising profiling or sell it to third parties.' },
    { title: '3. Document Security', body: `All documents uploaded through ${name} are protected with AES-256 encryption at rest and TLS 1.3 in transit. Documents are accessible only to our verified processing team. After your service is completed, all digital copies are permanently deleted from our servers within 30 days.` },
    { title: '4. Information Sharing', body: 'We do not sell, rent, or trade your personal information. We share information only with our verified on-ground representatives, payment processors (Razorpay), government authorities when legally required, and professional advisors bound by confidentiality obligations.' },
    { title: '5. Cookies and Tracking', body: 'We use strictly necessary cookies to maintain your login session and prevent fraud. We use Google Analytics (anonymised). We use no advertising or tracking cookies.' },
    { title: '6. Your Rights', body: `You have the right to: access all personal data we hold; correct inaccurate data; request deletion of your account and data; object to processing; and data portability. Contact us to exercise these rights. We will respond within 30 days.` },
    { title: '7. Data Retention', body: 'We retain your personal data for as long as your account is active and for 3 years after account closure for legal and audit purposes. Document uploads are deleted 30 days after service completion. Booking records are retained for 5 years for accounting compliance.' },
    { title: '8. Contact and Complaints', body: `For privacy-related questions, contact our Data Protection Officer through the contact form. We take all privacy complaints seriously and will respond within 30 days.` },
  ]

  return (
    <Layout>
      <LegalCustomCss css={settings.custom_css_global} />
      <div className="s2-privacy-page s2-marketing-page">
        <div className="s2-privacy-hero s2-surface-dark">
          <h1 className="s2-privacy-hero__title">Privacy Policy</h1>
          <p className="s2-privacy-hero__sub">Last updated: January 2025</p>
        </div>
        <div className="s2-privacy-body">
          <div className="s2-privacy-intro">
            <p>
              This Privacy Policy explains how <strong>{name}</strong> collects, uses, and protects your personal information when you use our platform. By using our services, you agree to the practices described in this policy.
            </p>
          </div>
          {sections.map((s) => (
            <div key={s.title} className="s2-privacy-card">
              <h2 className="s2-privacy-card__title">{s.title}</h2>
              <p className="s2-privacy-card__body">{s.body}</p>
            </div>
          ))}
        </div>
      </div>
    </Layout>
  )
}

// ── CityPage (Un) ─────────────────────────────────────────────────────────────
const CITY_DATA: Record<string, { name: string; state: string; emoji: string; pop: string; tagline: string; services: string[]; facts: string[] }> = {
  pune:       { name: 'Pune',      state: 'Maharashtra',  emoji: '🌆', pop: '6M+',  tagline: 'IT capital of India — trusted property management for NRI landlords', services: ['Property Management', 'Tenancy Management', 'Rental Agreement', 'Property Inspection', 'Housekeeping'], facts: ['Pune has 200,000+ NRI-owned properties', 'Average rental yield: 3-5% p.a.', 'Major IT hubs: Hinjewadi, Kharadi, Wakad'] },
  mumbai:     { name: 'Mumbai',    state: 'Maharashtra',  emoji: '🏙️', pop: '20M+', tagline: 'India\'s financial capital — premium NRI property management services', services: ['Property Management', 'Tenancy Management', 'Rental Agreement', 'Property Inspection', 'Housekeeping'], facts: ['Mumbai has highest NRI property concentration in India', 'Premium localities: Bandra, Andheri, Powai', 'Average rental yield: 2-4% p.a.'] },
  delhi:      { name: 'Delhi',     state: 'Delhi NCR',    emoji: '🕌', pop: '30M+', tagline: 'Capital city property management for NRIs — reliable and professional', services: ['Property Management', 'Tenancy Management', 'Rental Agreement', 'Property Inspection'], facts: ['Delhi NCR is India\'s fastest growing rental market', 'Key NRI localities: Noida, Gurgaon, South Delhi', 'Average rental yield: 3-5% p.a.'] },
  bangalore:  { name: 'Bangalore', state: 'Karnataka',    emoji: '🌃', pop: '12M+', tagline: 'Silicon Valley of India — professional NRI property management', services: ['Property Management', 'Tenancy Management', 'Rental Agreement', 'Housekeeping'], facts: ['Bangalore has highest rental demand in South India', 'IT corridors: Whitefield, Electronic City, Koramangala', 'Average rental yield: 4-6% p.a.'] },
  hyderabad:  { name: 'Hyderabad', state: 'Telangana',    emoji: '🕌', pop: '10M+', tagline: 'HITEC City NRI property management — reliable and transparent', services: ['Property Management', 'Tenancy Management', 'Rental Agreement'], facts: ['Hyderabad is fastest growing real estate market', 'Key areas: Gachibowli, Kondapur, Banjara Hills', 'Average rental yield: 4-5% p.a.'] },
  chennai:    { name: 'Chennai',   state: 'Tamil Nadu',   emoji: '🌊', pop: '10M+', tagline: 'South India\'s business hub — trusted NRI property services', services: ['Property Management', 'Tenancy Management', 'Rental Agreement'], facts: ['Chennai has strong NRI presence from USA and UK', 'Key localities: Anna Nagar, Adyar, OMR corridor', 'Average rental yield: 3-5% p.a.'] },
  ahmedabad:  { name: 'Ahmedabad', state: 'Gujarat',      emoji: '🏛️', pop: '8M+',  tagline: 'Gujarat\'s commercial capital — expert NRI property management', services: ['Property Management', 'Tenancy Management', 'Rental Agreement'], facts: ['Gujarat has highest NRI population per capita', 'Smart City initiative boosting real estate demand', 'Average rental yield: 3-4% p.a.'] },
  nagpur:     { name: 'Nagpur',    state: 'Maharashtra',  emoji: '🍊', pop: '3M+',  tagline: 'Heart of India — affordable NRI property management services', services: ['Property Management', 'Tenancy Management', 'Rental Agreement'], facts: ['Nagpur is emerging as logistics and IT hub', 'MIHAN project driving real estate growth', 'Average rental yield: 4-6% p.a. (above national average)'] },
}

const SERVICE_SLUG_MAP: Record<string, string> = {
  'Property Management': 'complete-property-management',
  'Tenancy Management':  'tenancy-management',
  'Rental Agreement':    'rent-agreement',
  'Property Inspection': 'complete-property-management',
  'Housekeeping':        'housekeeping-services',
}

export function CityPage() {
  const { city }  = useParams<{ city: string }>()
  const settings  = useStore((s) => s.settings)
  const name      = settings.platform_name || 'Services2NRI'

  // Extract city key from URL slug like "property-management-in-pune"
  const cityKey = (() => {
    if (!city) return ''
    const lower = city.toLowerCase()
    const idx   = lower.lastIndexOf('-in-')
    if (idx >= 0) return lower.slice(idx + 4)
    const parts = lower.split('-')
    return parts[parts.length - 1] || lower
  })()

  const data = CITY_DATA[cityKey] || {
    name:     city?.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) || 'City',
    state:    'India',
    emoji:    '🏙️',
    pop:      'N/A',
    tagline:  'Professional NRI property management services',
    services: ['Property Management', 'Tenancy Management', 'Rental Agreement'],
    facts:    [],
  }

  return (
    <Layout>
      <div className="s2-marketing-page s2-city-hero s2-surface-dark">
        <div className="s2-container">
          <p className="s2-city-hero__crumb">
            <Link to="/">Home</Link>
            {' › '}
            <Link to="/services/property">Property Management</Link>
            {' › '}
            {data.name}
          </p>
          <div className="s2-city-hero__head">
            <span className="s2-city-hero__emoji">{data.emoji}</span>
            <div>
              <h1 className="s2-city-hero__title">NRI Property Management in {data.name}</h1>
              <p className="s2-city-hero__sub">{data.state} · {data.pop} population · {data.tagline}</p>
            </div>
          </div>
          <div className="s2-city-hero__actions">
            <Link to="/contact" className="s2-city-hero__btn-primary">Get Free Quote for {data.name} →</Link>
            <Link to="/register" className="s2-city-hero__btn-ghost">Create Free Account</Link>
          </div>
        </div>
      </div>

      <PublicSection>
        <div className="s2-container">
          <h2 className="s2-public-section-title">Our Services in {data.name}</h2>
          <p className="s2-public-section-sub">{name} provides end-to-end property management for NRIs with properties in {data.name}, {data.state}.</p>
          <div className="s2-city-services-grid">
            {data.services.map((svc) => (
              <Link key={svc} to={`/service/${SERVICE_SLUG_MAP[svc] || 'complete-property-management'}`} className="s2-city-service-card">
                <div className="s2-city-service-card__icon">🏠</div>
                <div>
                  <h3 className="s2-city-service-card__title">{svc}</h3>
                  <p className="s2-city-service-card__link">Available in {data.name} →</p>
                </div>
              </Link>
            ))}
          </div>
        </div>
      </PublicSection>

      {/* Facts */}
      {data.facts.length > 0 && (
        <PublicSection alt>
          <div className="s2-container">
            <h2 className="s2-public-section-title s2-public-section-title--sm">{data.name} Real Estate — Key Facts for NRIs</h2>
            <div className="s2-city-facts-grid">
              {data.facts.map((fact, i) => (
                <div key={i} className="s2-city-fact-card">
                  <mark>✓</mark>
                  <p>{fact}</p>
                </div>
              ))}
            </div>
          </div>
        </PublicSection>
      )}

      <PublicSection>
        <div className="s2-container s2-width-content">
          <h2 className="s2-public-section-title s2-public-section-title--center s2-public-section-title--md">How We Manage Your {data.name} Property</h2>
          <div className="s2-city-process-grid">
            {[
              { n: '1', t: 'Free Consultation',  d: `Tell us about your ${data.name} property — location, type, current status. We give you a free assessment.` },
              { n: '2', t: 'Property Onboarding', d: 'We visit, photograph, and document your property condition. We handle any repairs needed before renting.' },
              { n: '3', t: 'Tenant Sourcing',    d: `We list your property, screen applicants, conduct police verification, and finalise the best tenant in ${data.name}.` },
              { n: '4', t: 'Agreement & Registration', d: 'Rental agreement is drafted, signed, and registered at the local sub-registrar. You get a certified copy.' },
              { n: '5', t: 'Monthly Management', d: 'Rent collected, bills paid, maintenance coordinated. Monthly statement and photos sent to you every month.' },
            ].map(({ n, t, d }) => (
              <div key={n} className="s2-city-process-card">
                <div className="s2-city-process-card__num">{n}</div>
                <div>
                  <div className="s2-city-process-card__title">{t}</div>
                  <div className="s2-city-process-card__desc">{d}</div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </PublicSection>

      <section className="s2-public-band-dark s2-surface-dark s2-marketing-page">
        <h2 className="s2-public-band-dark__title">Start Managing Your {data.name} Property Today</h2>
        <p className="s2-city-cta__sub">Get a free property assessment and management quote within 24 hours.</p>
        <div className="s2-public-band-dark__actions">
          <Link to="/contact" className="s2-public-band-dark__btn-primary">Get Free {data.name} Quote →</Link>
          <Link to="/pricing" className="s2-public-band-dark__btn-ghost">View Pricing Plans</Link>
        </div>
      </section>
    </Layout>
  )
}
