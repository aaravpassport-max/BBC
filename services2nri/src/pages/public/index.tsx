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
import { getAvatarImage, IMAGES } from '@/lib/images'
import type { FAQ, BlogPost, PricingPlan } from '@/types'
import { PublicSection, PublicSectionHead, PublicGrid, PublicCard, PublicCtaLink } from '@/components/public/PublicLayout'

// ── AboutPage (Bn) ────────────────────────────────────────────────────────────
export function AboutPage() {
  const settings = useStore((s) => s.settings)
  const primary  = resolvePrimary(settings)
  const name     = settings.platform_name || 'Services2NRI'
  const whatsapp = settings.platform_whatsapp

  const team = [
    { name: 'Arun Sharma',    role: 'Founder & CEO',           img: getAvatarImage('avatar_1'), bio: '15+ years in NRI services. Ex-Wall Street IT professional.' },
    { name: 'Priya Mehta',    role: 'Head of Operations',       img: getAvatarImage('avatar_3'), bio: '10+ years managing NRI documentation and legal processes.' },
    { name: 'Rahul Verma',    role: 'Lead Property Manager',    img: getAvatarImage('avatar_2'), bio: 'Certified property manager with pan-India network.' },
    { name: 'Deepa Krishnan', role: 'Financial Advisor',        img: getAvatarImage('avatar_5'), bio: 'CA with specialization in NRI taxation and investments.' },
  ]

  const values = [
    { icon: '🔒', t: 'Trust & Transparency', d: 'Every quote is itemised. Every document is encrypted. No surprises.' },
    { icon: '⚡', t: 'Speed & Efficiency',   d: 'We know time is precious. Most services delivered in 7–21 days.' },
    { icon: '🌍', t: 'Global Reach',          d: 'Serving NRIs in 50+ countries with round-the-clock support.' },
    { icon: '💎', t: 'Premium Quality',       d: 'Verified professionals, legal compliance, quality guarantees.' },
  ]

  return (
    <Layout>
      <PageHero title="About Us" subtitle={`Trusted NRI service partner since 2015 — making India management effortless from anywhere in the world.`} />

      <PublicSection>
        <div className="s2-mobile-stack" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 48, alignItems: 'center' }}>
          <div>
            <PublicSectionHead eyebrow="Our Story" title={settings.about_heading || `${name} — Your Bridge to India`} />
            <p className="s2-t-body" style={{ margin: '0 0 14px' }}>
              {settings.about_text || `${name} was founded with a single mission: to eliminate the paperwork stress that NRIs face when managing affairs back home.`}
            </p>
            <p className="s2-t-body" style={{ margin: '0 0 24px' }}>
              Our team of lawyers, CAs, property managers, and immigration specialists has helped over 10,000 NRIs across 50+ countries resolve their India-related needs without a single trip back home.
            </p>
            <PublicGrid min={120}>
              {[['10,000+', 'Clients Served'], ['44+', 'Services'], ['50+', 'Cities']].map(([val, lbl]) => (
                <PublicCard key={lbl} className="s2-public-icon-tile">
                  <div className="s2-text-primary" style={{ fontSize: 26, fontWeight: 900 }}>{val}</div>
                  <div className="s2-text-muted" style={{ fontSize: 12, marginTop: 3 }}>{lbl}</div>
                </PublicCard>
              ))}
            </PublicGrid>
            <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginTop: 24 }}>
              <PublicCtaLink to="/services">Our Services →</PublicCtaLink>
              {whatsapp && <a href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer" className="s2-btn s2-btn--secondary s2-btn--sm">💬 Chat with Us</a>}
            </div>
          </div>
          <div style={{ position: 'relative' }}>
            <img src={settings.about_image_url || IMAGES.about} alt={`About ${name}`} style={{ width: '100%', height: 360, objectFit: 'cover', borderRadius: 16, boxShadow: '0 8px 40px rgba(0,0,0,.15)' }} />
            {settings.about_video_url && (
              <a href={settings.about_video_url} target="_blank" rel="noopener noreferrer" style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', textDecoration: 'none' }}>
                <div style={{ width: 72, height: 72, background: 'rgba(255,255,255,.9)', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 4px 20px rgba(0,0,0,.25)' }}>
                  <span style={{ fontSize: 28, marginLeft: 4, color: primary }}>▶</span>
                </div>
              </a>
            )}
          </div>
        </div>
      </PublicSection>

      <PublicSection alt>
        <PublicSectionHead title="Our Core Values" />
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

      {/* Team */}
      <section style={{ padding: '56px 20px', background: '#fff' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto' }}>
          <h2 style={{ textAlign: 'center', fontSize: 'clamp(20px, 3vw, 30px)', fontWeight: 800, color: '#1E2D40', margin: '0 0 32px' }}>Meet Our Team</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 24 }}>
            {team.map(({ name: n, role, img, bio }) => (
              <div key={n} style={{ background: '#fff', border: '1px solid #EBF0F8', borderRadius: 14, overflow: 'hidden', textAlign: 'center' }}>
                <img src={img} alt={n} style={{ width: '100%', height: 200, objectFit: 'cover' }} />
                <div style={{ padding: '20px 16px' }}>
                  <h3 style={{ fontWeight: 700, fontSize: 16, margin: '0 0 4px', color: '#1E2D40' }}>{n}</h3>
                  <div style={{ fontSize: 13, color: primary, fontWeight: 600, marginBottom: 8 }}>{role}</div>
                  <p style={{ fontSize: 13, color: '#666', lineHeight: 1.6, margin: 0 }}>{bio}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* CTA */}
      <section style={{ background: `linear-gradient(135deg, #1E2D40 0%, ${primary} 100%)`, padding: '48px 20px', textAlign: 'center', color: '#fff' }}>
        <h2 style={{ fontSize: 'clamp(20px, 3vw, 32px)', fontWeight: 800, margin: '0 0 12px' }}>Ready to Get Started?</h2>
        <p style={{ fontSize: 15, opacity: 0.85, margin: '0 0 24px' }}>Let us handle your India affairs while you focus on what matters.</p>
        <div style={{ display: 'flex', gap: 12, justifyContent: 'center', flexWrap: 'wrap' }}>
          <Link to="/services" style={{ background: '#fff', color: primary, padding: '13px 30px', borderRadius: 9, fontWeight: 700, fontSize: 15, textDecoration: 'none' }}>Explore Services</Link>
          <Link to="/register" style={{ background: 'transparent', color: '#fff', padding: '13px 30px', borderRadius: 9, fontWeight: 600, fontSize: 15, textDecoration: 'none', border: '2px solid rgba(255,255,255,.5)' }}>Create Free Account</Link>
        </div>
      </section>
    </Layout>
  )
}

// ── ContactPage (Nn) ──────────────────────────────────────────────────────────
export function ContactPage() {
  const settings = useStore((s) => s.settings)
  const primary  = resolvePrimary(settings)
  const whatsapp = settings.platform_whatsapp

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

  const contacts = [
    { icon: '💬', t: 'WhatsApp (Fastest)', v: whatsapp ? `https://wa.me/${String(whatsapp).replace(/\D/g, '')}` : null, label: whatsapp ? `+${String(whatsapp).replace(/\D/g, '')}` : null },
    { icon: '✉️', t: 'Email',             v: settings.platform_email ? `mailto:${settings.platform_email}` : null, label: settings.platform_email },
    { icon: '📞', t: 'Phone',             v: settings.platform_phone ? `tel:${settings.platform_phone}` : null, label: settings.platform_phone },
    { icon: '📍', t: 'Address',           v: null, label: settings.platform_address || settings.platform_city || 'Pune, Maharashtra, India' },
  ].filter((c) => c.label)

  return (
    <Layout>
      <PageHero title="Contact Us" subtitle="We're here to help. Reach us via WhatsApp, email, or the form below." primary={primary} />
      <section style={{ padding: '64px 20px', background: '#fff' }}>
        <div className="s2-mobile-stack" style={{ maxWidth: 1100, margin: '0 auto', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 48 }}>
          {/* Contact info */}
          <div>
            <h2 style={{ fontSize: 26, fontWeight: 800, color: '#1E2D40', margin: '0 0 20px' }}>Get in Touch</h2>
            {contacts.map(({ icon, t, v, label }) => (
              <div key={t} style={{ display: 'flex', gap: 14, marginBottom: 20, alignItems: 'flex-start' }}>
                <div style={{ width: 44, height: 44, background: `${primary}15`, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 20, flexShrink: 0 }}>{icon}</div>
                <div>
                  <div style={{ fontSize: 12, fontWeight: 700, color: '#888', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 3 }}>{t}</div>
                  {v ? <a href={v} target="_blank" rel="noopener noreferrer" style={{ fontSize: 15, color: primary, fontWeight: 600, textDecoration: 'none' }}>{label}</a>
                     : <span style={{ fontSize: 15, color: '#333' }}>{label}</span>}
                </div>
              </div>
            ))}
            <div style={{ marginTop: 28, background: '#F5F7FA', borderRadius: 12, padding: 20, border: '1px solid #EBF0F8' }}>
              <h3 style={{ fontSize: 15, fontWeight: 700, margin: '0 0 8px', color: '#1E2D40' }}>Business Hours</h3>
              {[['Mon – Fri', '9:00 AM – 8:00 PM IST'], ['Saturday', '10:00 AM – 6:00 PM IST'], ['Sunday', 'Emergency Support Only']].map(([day, hrs]) => (
                <div key={day} style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, color: '#555', padding: '5px 0', borderBottom: '1px solid #f0f0f0' }}>
                  <span style={{ fontWeight: 500 }}>{day}</span>
                  <span>{hrs}</span>
                </div>
              ))}
            </div>
          </div>

          {/* Form */}
          <div>
            {success ? (
              <div style={{ textAlign: 'center', padding: '40px 20px' }}>
                <div style={{ fontSize: 52, marginBottom: 14 }}>✅</div>
                <h3 style={{ color: '#2e7d32', fontSize: 20, fontWeight: 700, margin: '0 0 8px' }}>Message Sent!</h3>
                <p style={{ color: '#555', fontSize: 14 }}>We'll get back to you within 24 hours. You can also WhatsApp us for faster response.</p>
                {whatsapp && <a href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer" style={{ display: 'inline-block', marginTop: 16, background: '#25d366', color: '#fff', padding: '10px 24px', borderRadius: 8, fontWeight: 700, textDecoration: 'none' }}>💬 WhatsApp Us</a>}
              </div>
            ) : failed ? (
              <div style={{ textAlign: 'center', padding: '40px 20px' }}>
                <div style={{ fontSize: 52, marginBottom: 14 }}>⚠️</div>
                <h3 style={{ color: '#b91c1c', fontSize: 20, fontWeight: 700, margin: '0 0 8px' }}>Couldn't send your message</h3>
                <p style={{ color: '#555', fontSize: 14, marginBottom: 4 }}>Something went wrong on our end. Please try again, or reach us directly on WhatsApp for a faster response.</p>
                {whatsapp && <a href={`https://wa.me/${String(whatsapp).replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer" style={{ display: 'inline-block', marginTop: 16, background: '#25d366', color: '#fff', padding: '10px 24px', borderRadius: 8, fontWeight: 700, textDecoration: 'none' }}>💬 WhatsApp Us</a>}
                <div style={{ marginTop: 16 }}>
                  <button onClick={() => setFailed(false)} style={{ background: 'none', border: 'none', color: primary, fontWeight: 700, cursor: 'pointer', fontSize: 14, textDecoration: 'underline' }}>← Try again</button>
                </div>
              </div>
            ) : (
              <div style={{ background: '#F5F7FA', borderRadius: 14, padding: 28, border: '1px solid #EBF0F8' }}>
                <h3 style={{ fontSize: 18, fontWeight: 700, color: '#1E2D40', margin: '0 0 20px' }}>Send Us a Message</h3>
                <div className="s2-about-grid" style={{ gridTemplateColumns: '1fr 1fr', display: 'grid', gap: 12, marginBottom: 12 }}>
                  {[['name', 'Full Name', 'text'], ['email', 'Email Address', 'email'], ['phone', 'Phone / WhatsApp', 'tel'], ['subject', 'Subject', 'text']].map(([field, ph, type]) => (
                    <input key={field} type={type} placeholder={ph} value={form[field as keyof typeof form]} onChange={(e) => setForm((f) => ({ ...f, [field]: e.target.value }))}
                      style={{ padding: '11px 14px', border: '1px solid #e0e0e0', borderRadius: 8, fontSize: 14, fontFamily: 'inherit', outline: 'none', background: '#fff', width: '100%', boxSizing: 'border-box' as const }} />
                  ))}
                </div>
                <textarea rows={5} placeholder="Your message — describe what you need..." value={form.message} onChange={(e) => setForm((f) => ({ ...f, message: e.target.value }))}
                  style={{ width: '100%', padding: '11px 14px', border: '1px solid #e0e0e0', borderRadius: 8, fontSize: 14, fontFamily: 'inherit', outline: 'none', background: '#fff', resize: 'vertical', boxSizing: 'border-box' as const, marginBottom: 14 }} />
                <button onClick={handleSubmit} disabled={loading}
                  style={{ width: '100%', background: primary, color: '#fff', border: 'none', padding: 13, borderRadius: 9, fontWeight: 700, fontSize: 15, cursor: loading ? 'wait' : 'pointer', opacity: loading ? 0.7 : 1 }}>
                  {loading ? 'Sending…' : 'Send Message →'}
                </button>
              </div>
            )}
          </div>
        </div>
      </section>
    </Layout>
  )
}

// ── HowItWorksPage ($n) ───────────────────────────────────────────────────────
export function HowItWorksPage() {
  const primary = resolvePrimary(useStore((s) => s.settings))

  const steps = [
    { n: 1, icon: '🔍', t: 'Browse & Select Service',    d: 'Visit our Services page and browse 44+ NRI services across 8 categories. Use the search bar or filter by category. Each service page shows the exact documents required, turnaround time, and a price range.' },
    { n: 2, icon: '📋', t: 'Submit Your Request',        d: 'Click "Place Service Inquiry" on the service page. Fill in the service-specific form (each service has a tailored form). You can also reach us directly on WhatsApp for a consultation first.' },
    { n: 3, icon: '📤', t: 'Upload Your Documents',      d: 'After submitting your request, you\'ll receive access to your secure dashboard. Upload your required documents through the encrypted portal. All files are AES-256 encrypted.' },
    { n: 4, icon: '✔️', t: 'Verification & Quote',       d: 'Our specialist team reviews your documents and application within 24 hours. You receive a detailed, itemised quote. No hidden charges — you see exactly what you are paying for.' },
    { n: 5, icon: '💳', t: 'Approve & Pay Securely',     d: 'Review the quote in your dashboard. Approve it and make payment via bank transfer, UPI, or Razorpay. For international clients, we accept SWIFT wire transfers.' },
    { n: 6, icon: '🚀', t: 'Receive Your Documents',     d: 'Our team handles the complete process with regular status updates. You track everything live in your dashboard. Documents are delivered to your overseas address or digitally as required.' },
  ]

  return (
    <Layout>
      <PageHero title="How It Works" subtitle="Get your NRI service done in 6 simple steps — from anywhere in the world." primary={primary} />
      <section style={{ padding: '64px 20px', background: '#fff' }}>
        <div style={{ maxWidth: 1000, margin: '0 auto' }}>
          {steps.map(({ n, icon, t, d }, i) => (
            <div key={n} style={{ display: 'flex', gap: 24, marginBottom: i < steps.length - 1 ? 40 : 0, alignItems: 'flex-start' }}>
              <div style={{ flexShrink: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 0 }}>
                <div style={{ width: 60, height: 60, background: primary, color: '#fff', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 26, boxShadow: `0 4px 14px ${primary}50`, flexShrink: 0 }}>{icon}</div>
                {i < steps.length - 1 && <div style={{ width: 2, height: 32, background: `${primary}30`, margin: '6px 0' }} />}
              </div>
              <div style={{ paddingTop: 8 }}>
                <div style={{ fontSize: 11, fontWeight: 700, color: primary, textTransform: 'uppercase', letterSpacing: 1.5, marginBottom: 4 }}>Step {n}</div>
                <h3 style={{ fontSize: 20, fontWeight: 700, color: '#1E2D40', margin: '0 0 8px' }}>{t}</h3>
                <p style={{ fontSize: 15, color: '#555', lineHeight: 1.8, margin: 0 }}>{d}</p>
              </div>
            </div>
          ))}

          <div style={{ marginTop: 48, background: `${primary}08`, borderRadius: 14, padding: '28px 32px', border: `1px solid ${primary}20`, textAlign: 'center' }}>
            <h3 style={{ fontWeight: 700, fontSize: 18, margin: '0 0 8px', color: '#1E2D40' }}>Have Questions? Talk to Us First.</h3>
            <p style={{ color: '#555', fontSize: 14, margin: '0 0 18px' }}>Our team is available 24/7 on WhatsApp for a free consultation before you place a request.</p>
            <Link to="/services" style={{ background: primary, color: '#fff', padding: '12px 28px', borderRadius: 9, fontWeight: 700, fontSize: 15, textDecoration: 'none', marginRight: 12 }}>Browse Services →</Link>
            <Link to="/contact" style={{ color: primary, fontWeight: 700, fontSize: 15, textDecoration: 'none', borderBottom: `2px solid ${primary}`, paddingBottom: 2 }}>Contact Us</Link>
          </div>
        </div>
      </section>
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
  const primary = resolvePrimary(useStore((s) => s.settings))
  const [openIdx, setOpenIdx] = useState<number | null>(null)
  const [faqs,    setFaqs]    = useState<FAQ[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.get<{ faqs: FAQ[] }>('faqs')
      .then((d) => { setFaqs(d.faqs || []); setLoading(false) })
      .catch(() => setLoading(false))
  }, [])

  const display = faqs.length > 0 ? faqs : FAQ_FALLBACK

  return (
    <Layout>
      <PageHero title="Frequently Asked Questions" subtitle="Everything you need to know before placing a service request." primary={primary} />
      <PublicSection className="s2-public-faq">
        <div style={{ maxWidth: 860, margin: '0 auto' }}>
          {loading ? (
            <div className="s2-text-muted" style={{ textAlign: 'center', padding: 40 }}>Loading FAQs…</div>
          ) : (
            display.map((faq, i) => (
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
            ))
          )}

          <PublicCard className="s2-public-cta-band" style={{ marginTop: 40, textAlign: 'center', padding: 28 }}>
            <h3 className="s2-t-h3" style={{ margin: '0 0 8px' }}>Still have questions?</h3>
            <p className="s2-t-body" style={{ margin: '0 0 16px' }}>Our team responds within 30 minutes on WhatsApp during business hours.</p>
            <PublicCtaLink to="/contact">Contact Us →</PublicCtaLink>
          </PublicCard>
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
  const primary = resolvePrimary(useStore((s) => s.settings))
  const [plans, setPlans] = useState<PricingPlan[]>([])

  useEffect(() => {
    api.get<{ plans: PricingPlan[] }>('pricing-plans')
      .then((d) => setPlans(d.plans || []))
      .catch(() => {})
  }, [])

  const display = plans.length > 0 ? plans : PRICING_FALLBACK

  return (
    <Layout>
      <div style={{ background: `linear-gradient(135deg, #1E2D40 0%, ${primary} 100%)`, color: '#fff', padding: '52px 20px', textAlign: 'center' }}>
        <p style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3, opacity: 0.75, margin: '0 0 10px' }}>Pricing</p>
        <h1 style={{ fontSize: 'clamp(26px, 4vw, 44px)', fontWeight: 900, margin: '0 0 12px' }}>The Perfect Balance of Features & Affordability</h1>
        <p style={{ fontSize: 16, opacity: 0.85, maxWidth: 560, margin: '0 auto' }}>Transparent pricing, no hidden charges. Pay only after approving your quote.</p>
        <div style={{ display: 'flex', gap: 16, justifyContent: 'center', marginTop: 20, flexWrap: 'wrap' }}>
          {['✅ Compliance-driven', '✅ Simple & intuitive', '✅ Straightforward pricing'].map((s) => <span key={s} style={{ fontSize: 13, opacity: 0.9 }}>{s}</span>)}
        </div>
      </div>

      {/* Plan cards */}
      <section style={{ padding: '64px 20px', background: '#F5F7FA' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto' }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: 24 }}>
            {display.map((plan) => (
              <div key={plan.id} style={{ background: '#fff', borderRadius: 16, overflow: 'hidden', boxShadow: plan.popular ? `0 8px 40px ${primary}30` : '0 2px 16px rgba(0,0,0,.07)', border: plan.popular ? `2px solid ${primary}` : '1px solid #EBF0F8', position: 'relative', transform: plan.popular ? 'scale(1.03)' : 'scale(1)' }}>
                {plan.popular && <div style={{ position: 'absolute', top: -1, left: 0, right: 0, background: primary, color: '#fff', padding: 6, textAlign: 'center', fontSize: 12, fontWeight: 700, letterSpacing: 1 }}>⭐ MOST POPULAR</div>}
                <div style={{ background: plan.popular ? primary : plan.color || '#374151', padding: plan.popular ? '40px 24px 24px' : '28px 24px 24px', color: '#fff' }}>
                  <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 2, opacity: 0.8, marginBottom: 4 }}>{plan.subtitle}</div>
                  <h3 style={{ fontSize: 24, fontWeight: 900, margin: '0 0 12px' }}>{plan.name}</h3>
                  <div style={{ fontSize: 'clamp(22px, 3vw, 32px)', fontWeight: 900 }}>{plan.price}</div>
                  {plan.price_note && <div style={{ fontSize: 13, opacity: 0.8, marginTop: 3 }}>{plan.price_note}</div>}
                </div>
                <div style={{ padding: 24 }}>
                  {(plan.features || []).map((f, i) => (
                    <div key={i} style={{ display: 'flex', gap: 10, marginBottom: 10, alignItems: 'flex-start', fontSize: 14, color: '#374151' }}>
                      <span style={{ color: '#2e7d32', fontWeight: 700, flexShrink: 0 }}>✓</span>
                      <span>{f}</span>
                    </div>
                  ))}
                  <Link to="/contact" style={{ display: 'block', marginTop: 20, textAlign: 'center', padding: 12, background: plan.popular ? primary : 'transparent', color: plan.popular ? '#fff' : primary, border: `2px solid ${primary}`, borderRadius: 9, fontWeight: 700, fontSize: 14, textDecoration: 'none' }}>
                    {plan.price === 'Free' ? 'Get Started Free' : 'Choose Plan →'}
                  </Link>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

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
      {plans.length === 0 && <section style={{ padding: '56px 20px', background: '#fff' }}>
        <div style={{ maxWidth: 1000, margin: '0 auto' }}>
          <h2 style={{ textAlign: 'center', fontSize: 'clamp(20px, 3vw, 30px)', fontWeight: 800, color: '#1E2D40', margin: '0 0 8px' }}>Services2NRI vs Others</h2>
          <p style={{ textAlign: 'center', color: '#666', margin: '0 0 32px' }}>See why NRIs choose us over traditional property managers</p>
          <div style={{ overflowX: 'auto' }}>
            <table className="s2-pricing-compare" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
              <thead>
                <tr style={{ background: `${primary}08` }}>
                  <th style={{ padding: '14px 16px', textAlign: 'left', fontWeight: 700, color: '#1E2D40', borderBottom: '2px solid #EBF0F8' }}>Feature</th>
                  {display.map((p) => <th key={p.id} style={{ padding: '14px 16px', textAlign: 'center', fontWeight: 700, color: p.popular ? primary : '#1E2D40', borderBottom: '2px solid #EBF0F8', whiteSpace: 'nowrap' }}>{p.name}</th>)}
                  <th style={{ padding: '14px 16px', textAlign: 'center', color: '#ef4444', fontWeight: 700, borderBottom: '2px solid #EBF0F8' }}>Others</th>
                </tr>
              </thead>
              <tbody>
                {COMPARE_FEATURES.map(({ feature, plans: vals }, i) => (
                  <tr key={feature} style={{ background: i % 2 === 0 ? '#fff' : '#fafafa' }}>
                    <td style={{ padding: '12px 16px', color: '#374151', borderBottom: '1px solid #f0f0f0' }}>{feature}</td>
                    {vals.map((v, j) => <td key={j} style={{ padding: '12px 16px', textAlign: 'center', borderBottom: '1px solid #f0f0f0' }}>{v ? <span style={{ color: '#2e7d32', fontSize: 18 }}>✓</span> : <span style={{ color: '#d1d5db', fontSize: 18 }}>—</span>}</td>)}
                    <td style={{ padding: '12px 16px', textAlign: 'center', borderBottom: '1px solid #f0f0f0' }}><span style={{ color: '#ef4444', fontSize: 18 }}>✕</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </section>}

      {/* NRIWAY vs Traditional */}
      <section style={{ padding: '48px 20px', background: '#fff' }}>
        <div style={{ maxWidth: 860, margin: '0 auto' }}>
          <h2 style={{ textAlign: 'center', fontSize: 'clamp(20px, 3vw, 28px)', fontWeight: 800, color: '#1E2D40', margin: '0 0 6px' }}>NRIWAY vs. Traditional Agents</h2>
          <p style={{ textAlign: 'center', color: '#666', fontSize: 14, margin: '0 0 28px' }}>See why NRIs across 50+ countries trust NRIWAY</p>
          <div style={{ overflowX: 'auto' }}>
            <table className="s2-pricing-compare" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
              <thead>
                <tr>
                  <th style={{ background: '#F5F7FA', padding: '14px 16px', textAlign: 'left', fontWeight: 700, color: '#374151', borderBottom: '2px solid #EBF0F8', width: '40%' }}>Feature</th>
                  <th style={{ background: primary, padding: '14px 16px', textAlign: 'center', fontWeight: 800, color: '#fff', borderBottom: `2px solid ${primary}` }}>NRIWAY</th>
                  <th style={{ background: '#F5F7FA', padding: '14px 16px', textAlign: 'center', fontWeight: 700, color: '#374151', borderBottom: '2px solid #EBF0F8' }}>Traditional Agents</th>
                </tr>
              </thead>
              <tbody>
                {[
                  ['Real-time booking tracking', '✅ Dashboard + email updates', '❌ Phone calls only'],
                  ['Transparent pricing',         '✅ Quote before any payment', '❌ Hidden charges common'],
                  ['Document security',           '✅ AES-256 encrypted portal', '❌ Via WhatsApp / email'],
                  ['Pan India coverage',          '✅ 750+ cities across India', '⚠️ Limited to few cities'],
                  ['Money-back guarantee',        '✅ On failed applications',   '❌ Non-refundable deposits'],
                  ['24/7 WhatsApp support',       '✅ Instant response team',    '⚠️ Office hours only'],
                  ['No upfront fee for quote',    '✅ Free consultation always', '❌ Retainer required'],
                ].map(([feat, us, them], i) => (
                  <tr key={feat} style={{ background: i % 2 === 0 ? '#fff' : '#F5F7FA' }}>
                    <td style={{ padding: '12px 16px', color: '#374151', fontWeight: 600, borderBottom: '1px solid #EBF0F8' }}>{feat}</td>
                    <td style={{ padding: '12px 16px', textAlign: 'center', color: '#15803d', fontWeight: 600, borderBottom: '1px solid #EBF0F8' }}>{us}</td>
                    <td style={{ padding: '12px 16px', textAlign: 'center', color: '#6b7280', borderBottom: '1px solid #EBF0F8' }}>{them}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section style={{ background: `${primary}08`, padding: '48px 20px', textAlign: 'center', borderTop: '1px solid #EBF0F8' }}>
        <h2 style={{ fontSize: 26, fontWeight: 800, color: '#1E2D40', margin: '0 0 10px' }}>Book a Free Consultation</h2>
        <p style={{ color: '#555', fontSize: 15, margin: '0 0 20px' }}>Not sure which plan is right for you? Talk to our team for free.</p>
        <Link to="/contact" style={{ background: primary, color: '#fff', padding: '13px 30px', borderRadius: 9, fontWeight: 700, fontSize: 15, textDecoration: 'none' }}>Book Free Consultation →</Link>
      </section>
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
  const primary  = resolvePrimary(useStore((s) => s.settings))
  const siteName = useStore((s) => s.settings).platform_name || 'Services2NRI'
  const [posts,      setPosts]      = useState<BlogPost[]>([])
  const [loading,    setLoading]    = useState(true)
  const [search,     setSearch]     = useState('')
  const [activeCategory, setActiveCategory] = useState('All')

  useEffect(() => {
    api.get<{ posts: BlogPost[] }>('blog?per_page=20')
      .then((d) => { setPosts(d.posts || []); setLoading(false) })
      .catch(() => setLoading(false))
  }, [])

  const allPosts = posts.length > 0 ? posts : BLOG_FALLBACK
  const featured = allPosts[0]
  const rest = allPosts.slice(1).filter(p => {
    const matchCat = activeCategory === 'All' || p.category === activeCategory
    const matchSearch = !search || p.title.toLowerCase().includes(search.toLowerCase()) || (p.excerpt || '').toLowerCase().includes(search.toLowerCase())
    return matchCat && matchSearch
  })

  return (
    <Layout>
      {/* Hero */}
      <div style={{ background: `linear-gradient(135deg, #1E2D40 0%, ${primary} 100%)`, padding: '56px 20px', color: '#fff' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto' }}>
          <p style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3, opacity: 0.7, margin: '0 0 10px' }}>NRI Knowledge Hub</p>
          <h1 style={{ fontSize: 'clamp(26px, 4vw, 48px)', fontWeight: 900, margin: '0 0 12px', maxWidth: 600, lineHeight: 1.2 }}>Expert Guides for NRIs Living Abroad</h1>
          <p style={{ fontSize: 16, opacity: 0.8, maxWidth: 500, margin: '0 0 28px', lineHeight: 1.7 }}>Real advice on OCI cards, property management, taxation, and more — written by {siteName} specialists.</p>
          {/* Search */}
          <div style={{ display: 'flex', maxWidth: 480, background: 'rgba(255,255,255,.12)', borderRadius: 10, border: '1px solid rgba(255,255,255,.2)', overflow: 'hidden' }}>
            <input type="search" value={search} onChange={e => setSearch(e.target.value)} placeholder="Search articles…"
              style={{ flex: 1, background: 'transparent', border: 'none', padding: '13px 16px', fontSize: 15, color: '#fff', outline: 'none' }} />
            <div style={{ padding: '13px 16px', fontSize: 16, opacity: 0.6 }}>🔍</div>
          </div>
        </div>
      </div>

      {/* Category filters */}
      <div style={{ background: '#F5F7FA', borderBottom: '1px solid #EBF0F8', padding: '12px 20px', overflowX: 'auto' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto', display: 'flex', gap: 8, whiteSpace: 'nowrap' }}>
          {BLOG_CATEGORIES.map(cat => (
            <button key={cat} onClick={() => setActiveCategory(cat)}
              style={{ padding: '6px 16px', borderRadius: 99, border: `1.5px solid ${activeCategory === cat ? primary : '#e0e0e0'}`, background: activeCategory === cat ? primary : '#fff', color: activeCategory === cat ? '#fff' : '#374151', fontWeight: activeCategory === cat ? 700 : 500, fontSize: 13, cursor: 'pointer', flexShrink: 0 }}>
              {cat}
            </button>
          ))}
        </div>
      </div>

      <div style={{ maxWidth: 1200, margin: '0 auto', padding: '48px 20px 72px' }}>
        {loading ? (
          <div style={{ textAlign: 'center', padding: 60, color: '#888' }}><div style={{ fontSize: 40, marginBottom: 12 }}>📖</div>Loading articles…</div>
        ) : (
          <>
            {/* Featured post */}
            {featured && activeCategory === 'All' && !search && (
              <Link to={`/blog/${featured.slug}`} className="s2-mobile-stack" style={{ textDecoration: 'none', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 0, background: '#1E2D40', borderRadius: 16, overflow: 'hidden', marginBottom: 48, boxShadow: '0 4px 32px rgba(0,0,0,.18)' }}
                onMouseEnter={e => { e.currentTarget.style.transform = 'translateY(-2px)'; e.currentTarget.style.boxShadow = '0 8px 40px rgba(0,0,0,.22)' }}
                onMouseLeave={e => { e.currentTarget.style.transform = ''; e.currentTarget.style.boxShadow = '0 4px 32px rgba(0,0,0,.18)' }}>
                <img src={featured.img || featured.image_url || IMAGES.about} alt={featured.title} style={{ width: '100%', height: 320, objectFit: 'cover', display: 'block' }} />
                <div style={{ padding: '40px 36px', display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
                  <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 14 }}>
                    <span style={{ fontSize: 11, fontWeight: 700, background: `${primary}30`, color: primary, padding: '4px 12px', borderRadius: 99, textTransform: 'uppercase', letterSpacing: 1 }}>Featured</span>
                    <span style={{ fontSize: 11, fontWeight: 600, color: '#6b7280' }}>{featured.category}</span>
                  </div>
                  <h2 style={{ fontSize: 'clamp(18px, 2vw, 24px)', fontWeight: 900, color: '#fff', margin: '0 0 12px', lineHeight: 1.3 }}>{featured.title}</h2>
                  <p style={{ fontSize: 14, color: '#9ca3af', lineHeight: 1.75, margin: '0 0 20px' }}>{featured.excerpt}</p>
                  <div style={{ display: 'flex', gap: 14, alignItems: 'center', fontSize: 12, color: '#6b7280' }}>
                    <span>📅 {featured.date || featured.created_at?.split(' ')[0]}</span>
                    <span>⏱ {featured.read_time || estimateReadTime(featured.content)}</span>
                  </div>
                  <div style={{ marginTop: 20, display: 'inline-flex', alignItems: 'center', gap: 6, background: primary, color: '#fff', padding: '10px 20px', borderRadius: 8, fontWeight: 700, fontSize: 14, width: 'fit-content' }}>
                    Read Article →
                  </div>
                </div>
              </Link>
            )}

            {/* Article grid */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))', gap: 28 }}>
              {rest.map(post => (
                <Link key={post.id} to={`/blog/${post.slug}`} style={{ textDecoration: 'none', borderRadius: 14, overflow: 'hidden', background: '#fff', boxShadow: '0 2px 12px rgba(0,0,0,.07)', display: 'flex', flexDirection: 'column', border: '1px solid #EBF0F8', transition: 'transform .2s, box-shadow .2s' }}
                  onMouseEnter={e => { e.currentTarget.style.transform = 'translateY(-4px)'; e.currentTarget.style.boxShadow = '0 8px 28px rgba(0,0,0,.13)' }}
                  onMouseLeave={e => { e.currentTarget.style.transform = ''; e.currentTarget.style.boxShadow = '0 2px 12px rgba(0,0,0,.07)' }}>
                  <div style={{ position: 'relative', height: 200, overflow: 'hidden' }}>
                    <img src={post.img || post.image_url || IMAGES.about} alt={post.title} style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block', transition: 'transform .3s' }}
                      onMouseEnter={e => { (e.currentTarget as HTMLImageElement).style.transform = 'scale(1.04)' }}
                      onMouseLeave={e => { (e.currentTarget as HTMLImageElement).style.transform = '' }} />
                    <div style={{ position: 'absolute', top: 12, left: 12, background: `${primary}ee`, color: '#fff', padding: '3px 10px', borderRadius: 99, fontSize: 11, fontWeight: 700 }}>{post.category}</div>
                  </div>
                  <div style={{ padding: '20px 22px 24px', flex: 1, display: 'flex', flexDirection: 'column' }}>
                    <h3 style={{ fontSize: 16, fontWeight: 800, color: '#1E2D40', margin: '0 0 10px', lineHeight: 1.4 }}>{post.title}</h3>
                    <p style={{ fontSize: 13, color: '#555', lineHeight: 1.75, margin: '0 0 16px', flex: 1 }}>{post.excerpt}</p>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingTop: 12, borderTop: '1px solid #f0f0f0' }}>
                      <div style={{ display: 'flex', gap: 10, fontSize: 12, color: '#888' }}>
                        <span>📅 {post.date || post.created_at?.split(' ')[0]}</span>
                        <span>⏱ {post.read_time || estimateReadTime(post.content)}</span>
                      </div>
                      <span style={{ fontSize: 13, fontWeight: 700, color: primary }}>Read →</span>
                    </div>
                  </div>
                </Link>
              ))}
            </div>

            {rest.length === 0 && (
              <div style={{ textAlign: 'center', padding: '60px 20px', color: '#888' }}>
                <div style={{ fontSize: 48, marginBottom: 16 }}>🔍</div>
                <h3 style={{ color: '#374151', fontSize: 18, margin: '0 0 8px' }}>No articles found</h3>
                <p style={{ fontSize: 14 }}>Try a different search term or category.</p>
                <button onClick={() => { setSearch(''); setActiveCategory('All') }} style={{ marginTop: 16, background: primary, color: '#fff', border: 'none', padding: '10px 24px', borderRadius: 8, fontWeight: 700, cursor: 'pointer' }}>Clear Filters</button>
              </div>
            )}
          </>
        )}
      </div>
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
  const primary   = resolvePrimary(useStore((s) => s.settings))
  const siteName  = useStore((s) => s.settings).platform_name || 'Services2NRI'
  const [post,    setPost]    = useState<BlogPost | null>(null)
  const [related, setRelated] = useState<BlogPost[]>([])
  const [loading, setLoading] = useState(true)
  const [loadFailed, setLoadFailed] = useState(false)

  const loadPost = useCallback(() => {
    if (!slug) return
    setLoading(true)
    setLoadFailed(false)
    api.get<{ post: BlogPost }>(`blog/${slug}`)
      .then((d) => { setPost(d.post || null); setLoading(false) })
      // FIXED: same "not found vs load failed" conflation fixed
      // repeatedly this session elsewhere — BLOG_DETAIL_FALLBACK only
      // covers one hardcoded slug ('oci-card-renewal-guide'), so any
      // OTHER real blog post hitting a transient server error previously
      // showed "Article not found" (a false claim) instead of a retry
      // option. Uses the same status-field distinction already
      // established and verified with api.ts's thrown error shape.
      .catch((e: unknown) => {
        setLoading(false)
        const status = (e as { status?: number })?.status
        if (status !== 404) setLoadFailed(true)
      })
    // Load related posts
    api.get<{ posts: BlogPost[] }>('blog?per_page=4')
      .then(d => setRelated((d.posts || []).filter(p => p.slug !== slug).slice(0, 3)))
      .catch(() => {})
  }, [slug])

  useEffect(() => { loadPost() }, [loadPost])

  const display = post || (slug ? BLOG_DETAIL_FALLBACK[slug] : null)
  const readTime = display?.read_time || estimateReadTime(display?.content)

  if (loading) {
    return <Layout><div style={{ padding: 80, textAlign: 'center', color: '#888' }}>
      <div style={{ width: 40, height: 40, border: `4px solid ${primary}30`, borderTop: `4px solid ${primary}`, borderRadius: '50%', animation: 'spin .7s linear infinite', margin: '0 auto 16px' }} />
      Loading article…
    </div></Layout>
  }

  if (loadFailed) {
    return (
      <Layout>
        <div style={{ padding: 80, textAlign: 'center' }}>
          <div style={{ fontSize: 48, marginBottom: 16 }}>⚠️</div>
          <h2 style={{ color: '#374151', marginBottom: 10 }}>Couldn't load this article</h2>
          <p style={{ color: '#6b7280', marginBottom: 20 }}>Something went wrong on our end. Please try again.</p>
          <button onClick={loadPost} style={{ background: primary, color: '#fff', border: 'none', padding: '10px 24px', borderRadius: 8, fontWeight: 700, cursor: 'pointer', fontSize: 14 }}>Retry</button>
        </div>
      </Layout>
    )
  }

  if (!display) {
    return (
      <Layout>
        <div style={{ padding: 80, textAlign: 'center' }}>
          <div style={{ fontSize: 48, marginBottom: 16 }}>📄</div>
          <h2 style={{ color: '#374151', marginBottom: 10 }}>Article not found</h2>
          <Link to="/blog" style={{ color: primary, fontWeight: 700, textDecoration: 'none' }}>← Back to Knowledge Hub</Link>
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
      {/* Article hero */}
      <div style={{ background: `linear-gradient(to bottom, #1E2D40 0%, ${primary}cc 100%)`, padding: '48px 20px 56px', color: '#fff' }}>
        <div style={{ maxWidth: 860, margin: '0 auto' }}>
          <Link to="/blog" style={{ display: 'inline-flex', alignItems: 'center', gap: 6, color: 'rgba(255,255,255,.7)', fontSize: 13, textDecoration: 'none', marginBottom: 20, fontWeight: 500 }}>← Knowledge Hub</Link>
          <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginBottom: 16, flexWrap: 'wrap' }}>
            <span style={{ fontSize: 12, fontWeight: 700, background: `${primary}40`, color: '#fff', padding: '4px 12px', borderRadius: 99, border: `1px solid ${primary}60` }}>{display.category}</span>
            <span style={{ fontSize: 12, color: 'rgba(255,255,255,.65)' }}>📅 {display.date || display.created_at?.split(' ')[0]}</span>
            <span style={{ fontSize: 12, color: 'rgba(255,255,255,.65)' }}>⏱ {readTime}</span>
          </div>
          <h1 style={{ fontSize: 'clamp(22px, 4vw, 40px)', fontWeight: 900, lineHeight: 1.25, color: '#fff', margin: '0 0 16px' }}>{display.title}</h1>
          {display.excerpt && <p style={{ fontSize: 16, color: 'rgba(255,255,255,.8)', lineHeight: 1.75, margin: 0, maxWidth: 680 }}>{display.excerpt}</p>}
        </div>
      </div>

      {display.image_url && (
        <div style={{ maxWidth: 860, margin: '0 auto', padding: '0 20px', marginTop: -32 }}>
          <img src={display.image_url} alt={display.title} style={{ width: '100%', borderRadius: 14, boxShadow: '0 8px 40px rgba(0,0,0,.2)', maxHeight: 420, objectFit: 'cover', display: 'block' }} />
        </div>
      )}

      {/* Content + ToC */}
      <div className="s2-mobile-stack" style={{ maxWidth: 1200, margin: '0 auto', padding: '48px 20px 80px', display: 'grid', gridTemplateColumns: headings.length > 2 ? '1fr 260px' : '1fr', gap: 48, alignItems: 'flex-start' }}>

        {/* Main article */}
        <article>
          <style>{`
            article h1, article h2, article h3 { color: #1E2D40; line-height: 1.35; margin: 2em 0 .75em; }
            article h2 { font-size: clamp(20px, 2.5vw, 26px); font-weight: 800; border-bottom: 2px solid #EBF0F8; padding-bottom: 8px; }
            article h3 { font-size: clamp(17px, 2vw, 21px); font-weight: 700; }
            article p  { font-size: 16px; line-height: 1.9; color: #374151; margin: 0 0 1.2em; }
            article ul, article ol { padding-left: 24px; margin: 0 0 1.2em; }
            article li { font-size: 15px; line-height: 1.8; color: #374151; margin-bottom: 6px; }
            article a  { color: ${primary}; text-decoration: underline; font-weight: 500; }
            article blockquote { margin: 1.5em 0; padding: 16px 20px 16px 24px; border-left: 4px solid ${primary}; background: ${primary}08; border-radius: 0 8px 8px 0; font-style: italic; }
            article strong { color: #1E2D40; font-weight: 700; }
            article code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 0.9em; }
          `}</style>
          <div dangerouslySetInnerHTML={{ __html: contentWithIds || '<p>Content coming soon.</p>' }} />

          {/* Social sharing */}
          <div style={{ marginTop: 48, padding: '24px 28px', background: '#F5F7FA', borderRadius: 12, border: '1px solid #EBF0F8' }}>
            <p style={{ fontSize: 14, fontWeight: 700, color: '#374151', margin: '0 0 12px' }}>Share this article</p>
            <div style={{ display: 'flex', gap: 10 }}>
              {[
                { label: '🐦 Twitter/X', url: `https://twitter.com/intent/tweet?text=${encodeURIComponent(display.title)}&url=${encodeURIComponent(window.location.href)}` },
                { label: '💼 LinkedIn', url: `https://www.linkedin.com/shareArticle?mini=true&url=${encodeURIComponent(window.location.href)}&title=${encodeURIComponent(display.title)}` },
                { label: '💬 WhatsApp', url: `https://wa.me/?text=${encodeURIComponent(display.title + ' ' + window.location.href)}` },
              ].map(s => (
                <a key={s.label} href={s.url} target="_blank" rel="noopener noreferrer"
                  style={{ fontSize: 13, fontWeight: 600, padding: '8px 16px', borderRadius: 7, background: '#fff', border: '1px solid #e0e0e0', textDecoration: 'none', color: '#374151', transition: 'border-color .15s' }}
                  onMouseEnter={e => { (e.currentTarget as HTMLAnchorElement).style.borderColor = primary }}
                  onMouseLeave={e => { (e.currentTarget as HTMLAnchorElement).style.borderColor = '#e0e0e0' }}>
                  {s.label}
                </a>
              ))}
            </div>
          </div>

          {/* CTA */}
          <div style={{ marginTop: 32, background: `linear-gradient(135deg, #1E2D40 0%, ${primary} 100%)`, borderRadius: 14, padding: '32px 28px', color: '#fff', display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 20 }}>
            <div>
              <h3 style={{ fontSize: 20, fontWeight: 800, margin: '0 0 6px' }}>Need Help With {display.category} Services?</h3>
              <p style={{ fontSize: 14, opacity: 0.85, margin: 0 }}>{siteName} experts handle everything. Get a free quote today.</p>
            </div>
            <Link to="/services" style={{ background: '#fff', color: primary, padding: '12px 24px', borderRadius: 9, fontWeight: 700, fontSize: 14, textDecoration: 'none', flexShrink: 0 }}>Explore Services →</Link>
          </div>

          {/* Related posts */}
          {related.length > 0 && (
            <div style={{ marginTop: 48 }}>
              <h2 style={{ fontSize: 22, fontWeight: 800, color: '#1E2D40', margin: '0 0 20px', borderBottom: '2px solid #EBF0F8', paddingBottom: 8 }}>Related Articles</h2>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 20 }}>
                {related.map(r => (
                  <Link key={r.id} to={`/blog/${r.slug}`} style={{ textDecoration: 'none', borderRadius: 10, overflow: 'hidden', background: '#fff', border: '1px solid #EBF0F8', boxShadow: '0 2px 8px rgba(0,0,0,.05)', display: 'flex', flexDirection: 'column' }}>
                    <img src={r.img || r.image_url || IMAGES.about} alt={r.title} style={{ width: '100%', height: 120, objectFit: 'cover' }} />
                    <div style={{ padding: '14px 16px' }}>
                      <div style={{ fontSize: 11, fontWeight: 700, color: primary, marginBottom: 6 }}>{r.category}</div>
                      <h4 style={{ fontSize: 14, fontWeight: 700, color: '#1E2D40', margin: 0, lineHeight: 1.4 }}>{r.title}</h4>
                    </div>
                  </Link>
                ))}
              </div>
            </div>
          )}
        </article>

        {/* Sticky table of contents */}
        {headings.length > 2 && (
          <aside style={{ position: 'sticky', top: 80, background: '#F5F7FA', borderRadius: 12, border: '1px solid #EBF0F8', padding: '20px 0', overflow: 'hidden' }}>
            <div style={{ padding: '0 18px 12px', fontSize: 11, fontWeight: 800, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: 1.5, borderBottom: '1px solid #EBF0F8', marginBottom: 8 }}>Table of Contents</div>
            {headings.map(h => (
              <a key={h.id} href={`#${h.id}`}
                onClick={e => { e.preventDefault(); document.getElementById(h.id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }}
                style={{ display: 'block', padding: `7px ${h.level === 3 ? 24 : 16}px`, fontSize: h.level === 3 ? 12 : 13, fontWeight: h.level === 2 ? 600 : 500, color: '#555', textDecoration: 'none', lineHeight: 1.4, transition: 'color .15s, background .15s' }}
                onMouseEnter={e => { (e.currentTarget as HTMLAnchorElement).style.color = primary; (e.currentTarget as HTMLAnchorElement).style.background = `${primary}08` }}
                onMouseLeave={e => { (e.currentTarget as HTMLAnchorElement).style.color = '#555'; (e.currentTarget as HTMLAnchorElement).style.background = '' }}>
                {h.level === 3 && <span style={{ marginRight: 6, opacity: 0.4 }}>↳</span>}{h.text}
              </a>
            ))}
          </aside>
        )}
      </div>
    </Layout>
  )
}

// ── TermsPage (Vn) ────────────────────────────────────────────────────────────
export function TermsPage() {
  const settings = useStore((s) => s.settings)
  const primary  = resolvePrimary(settings)
  const name     = settings.platform_name || 'Services2NRI'

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
      <PageHero title="Terms & Conditions" subtitle="Last updated: January 2025" primary={primary} />
      <div style={{ maxWidth: 860, margin: '0 auto', padding: '56px 20px' }}>
        {sections.map(([title, body]) => (
          <div key={title} style={{ marginBottom: 28 }}>
            <h2 style={{ fontSize: 18, fontWeight: 700, color: '#1E2D40', margin: '0 0 8px' }}>{title}</h2>
            <p style={{ fontSize: 15, color: '#555', lineHeight: 1.8, margin: 0 }}>{body}</p>
          </div>
        ))}
      </div>
    </Layout>
  )
}

// ── PrivacyPage (Yn) ──────────────────────────────────────────────────────────
export function PrivacyPage() {
  const settings = useStore((s) => s.settings)
  const primary  = resolvePrimary(settings)
  const name     = settings.platform_name || 'Services2NRI'

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
    <div style={{ background: '#F5F7FA', minHeight: '100vh' }}>
      <div style={{ background: primary, padding: '48px 20px', textAlign: 'center' }}>
        <h1 style={{ color: '#fff', fontSize: 'clamp(24px, 3vw, 36px)', fontWeight: 900, margin: '0 0 10px' }}>Privacy Policy</h1>
        <p style={{ color: 'rgba(255,255,255,.85)', fontSize: 16, margin: 0 }}>Last updated: January 2025</p>
      </div>
      <div style={{ maxWidth: 800, margin: '0 auto', padding: '40px 20px 80px' }}>
        <div style={{ background: '#fff', border: `1px solid ${primary}30`, borderRadius: 12, padding: '20px 24px', marginBottom: 28 }}>
          <p style={{ fontSize: 15, color: '#374151', lineHeight: 1.8, margin: 0 }}>
            This Privacy Policy explains how <strong>{name}</strong> collects, uses, and protects your personal information when you use our platform. By using our services, you agree to the practices described in this policy.
          </p>
        </div>
        {sections.map((s) => (
          <div key={s.title} style={{ background: '#fff', border: '1px solid #EBF0F8', borderRadius: 12, padding: '24px 28px', marginBottom: 16 }}>
            <h2 style={{ fontSize: 17, fontWeight: 800, color: primary, margin: '0 0 12px' }}>{s.title}</h2>
            <p style={{ fontSize: 14, color: '#374151', lineHeight: 1.85, margin: 0 }}>{s.body}</p>
          </div>
        ))}
      </div>
    </div>
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
  const primary   = resolvePrimary(settings)
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
      {/* Hero */}
      <div style={{ background: `linear-gradient(135deg, #1E2D40 0%, ${primary} 100%)`, color: '#fff', padding: '56px 20px' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto' }}>
          <p style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 3, opacity: 0.75, margin: '0 0 8px' }}>
            <Link to="/" style={{ color: 'rgba(255,255,255,.7)', textDecoration: 'none' }}>Home</Link>
            {' › '}
            <Link to="/services/property" style={{ color: 'rgba(255,255,255,.7)', textDecoration: 'none' }}>Property Management</Link>
            {' › '}
            {data.name}
          </p>
          <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 16 }}>
            <span style={{ fontSize: 52 }}>{data.emoji}</span>
            <div>
              <h1 style={{ fontSize: 'clamp(24px, 4vw, 44px)', fontWeight: 900, margin: '0 0 6px' }}>NRI Property Management in {data.name}</h1>
              <p style={{ fontSize: 15, opacity: 0.85, margin: 0 }}>{data.state} · {data.pop} population · {data.tagline}</p>
            </div>
          </div>
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
            <Link to="/contact" style={{ background: '#fff', color: primary, padding: '11px 24px', borderRadius: 8, fontWeight: 700, fontSize: 14, textDecoration: 'none' }}>Get Free Quote for {data.name} →</Link>
            <Link to="/register" style={{ background: 'transparent', color: '#fff', padding: '11px 24px', borderRadius: 8, fontWeight: 600, fontSize: 14, textDecoration: 'none', border: '2px solid rgba(255,255,255,.5)' }}>Create Free Account</Link>
          </div>
        </div>
      </div>

      {/* Services */}
      <section style={{ padding: '56px 20px', background: '#fff' }}>
        <div style={{ maxWidth: 1200, margin: '0 auto' }}>
          <h2 style={{ fontSize: 'clamp(20px, 3vw, 30px)', fontWeight: 800, color: '#1E2D40', margin: '0 0 8px' }}>Our Services in {data.name}</h2>
          <p style={{ color: '#666', fontSize: 15, margin: '0 0 28px' }}>{name} provides end-to-end property management for NRIs with properties in {data.name}, {data.state}.</p>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 20 }}>
            {data.services.map((svc) => (
              <Link key={svc} to={`/service/${SERVICE_SLUG_MAP[svc] || 'complete-property-management'}`}
                style={{ textDecoration: 'none', background: '#fff', border: '1px solid #EBF0F8', borderRadius: 12, padding: 20, display: 'flex', gap: 14, alignItems: 'flex-start', transition: 'box-shadow .2s, transform .2s' }}
                onMouseEnter={(e) => { e.currentTarget.style.boxShadow = '0 6px 20px rgba(0,0,0,.1)'; e.currentTarget.style.transform = 'translateY(-2px)' }}
                onMouseLeave={(e) => { e.currentTarget.style.boxShadow = ''; e.currentTarget.style.transform = '' }}
              >
                <div style={{ width: 44, height: 44, background: `${primary}15`, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 22, flexShrink: 0 }}>🏠</div>
                <div>
                  <h3 style={{ fontSize: 15, fontWeight: 700, color: '#1E2D40', margin: '0 0 4px' }}>{svc}</h3>
                  <p style={{ fontSize: 13, color: primary, fontWeight: 600, margin: 0 }}>Available in {data.name} →</p>
                </div>
              </Link>
            ))}
          </div>
        </div>
      </section>

      {/* Facts */}
      {data.facts.length > 0 && (
        <section style={{ padding: '48px 20px', background: '#F5F7FA' }}>
          <div style={{ maxWidth: 1200, margin: '0 auto' }}>
            <h2 style={{ fontSize: 'clamp(18px, 2.5vw, 26px)', fontWeight: 800, color: '#1E2D40', margin: '0 0 20px' }}>{data.name} Real Estate — Key Facts for NRIs</h2>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 16 }}>
              {data.facts.map((fact, i) => (
                <div key={i} style={{ background: '#fff', borderRadius: 10, padding: '18px 20px', border: '1px solid #EBF0F8', display: 'flex', gap: 12, alignItems: 'flex-start' }}>
                  <span style={{ color: primary, fontWeight: 700, fontSize: 18, flexShrink: 0 }}>✓</span>
                  <p style={{ margin: 0, fontSize: 14, color: '#374151', lineHeight: 1.6 }}>{fact}</p>
                </div>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* Process */}
      <section style={{ padding: '48px 20px', background: '#fff' }}>
        <div style={{ maxWidth: 900, margin: '0 auto' }}>
          <h2 style={{ fontSize: 22, fontWeight: 800, color: '#1E2D40', margin: '0 0 24px', textAlign: 'center' }}>How We Manage Your {data.name} Property</h2>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: 16 }}>
            {[
              { n: '1', t: 'Free Consultation',  d: `Tell us about your ${data.name} property — location, type, current status. We give you a free assessment.` },
              { n: '2', t: 'Property Onboarding', d: 'We visit, photograph, and document your property condition. We handle any repairs needed before renting.' },
              { n: '3', t: 'Tenant Sourcing',    d: `We list your property, screen applicants, conduct police verification, and finalise the best tenant in ${data.name}.` },
              { n: '4', t: 'Agreement & Registration', d: 'Rental agreement is drafted, signed, and registered at the local sub-registrar. You get a certified copy.' },
              { n: '5', t: 'Monthly Management', d: 'Rent collected, bills paid, maintenance coordinated. Monthly statement and photos sent to you every month.' },
            ].map(({ n, t, d }) => (
              <div key={n} style={{ background: '#F5F7FA', borderRadius: 10, padding: '18px 20px', display: 'flex', gap: 12, alignItems: 'flex-start' }}>
                <div style={{ width: 32, height: 32, background: primary, color: '#fff', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, fontSize: 14, flexShrink: 0 }}>{n}</div>
                <div>
                  <div style={{ fontWeight: 700, fontSize: 14, color: '#1E2D40', marginBottom: 4 }}>{t}</div>
                  <div style={{ fontSize: 13, color: '#555', lineHeight: 1.6 }}>{d}</div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* CTA */}
      <section style={{ background: `linear-gradient(135deg, #1E2D40 0%, ${primary} 100%)`, padding: '48px 20px', textAlign: 'center', color: '#fff' }}>
        <h2 style={{ fontSize: 'clamp(20px, 3vw, 32px)', fontWeight: 800, margin: '0 0 10px' }}>Start Managing Your {data.name} Property Today</h2>
        <p style={{ fontSize: 15, opacity: 0.85, margin: '0 0 24px', maxWidth: 500, marginLeft: 'auto', marginRight: 'auto' }}>
          Get a free property assessment and management quote within 24 hours.
        </p>
        <div style={{ display: 'flex', gap: 12, justifyContent: 'center', flexWrap: 'wrap' }}>
          <Link to="/contact" style={{ background: '#fff', color: primary, padding: '13px 28px', borderRadius: 9, fontWeight: 700, fontSize: 15, textDecoration: 'none' }}>Get Free {data.name} Quote →</Link>
          <Link to="/pricing" style={{ background: 'transparent', color: '#fff', padding: '13px 28px', borderRadius: 9, fontWeight: 600, fontSize: 15, textDecoration: 'none', border: '2px solid rgba(255,255,255,.5)' }}>View Pricing Plans</Link>
        </div>
      </section>
    </Layout>
  )
}
