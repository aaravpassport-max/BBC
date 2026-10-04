// ── Global window config ─────────────────────────────────────────────────────
export interface S2NRIConfig {
  apiBase: string
  spaBase: string
  assetsUrl: string
  nonce: string
  portalToken: string
  version: string
  currentUser: User | null
  settings: Settings
  builderUrl: string
  basePath?: string   // injected by Portal.php — e.g. '/s2nri-admin' or '/portal'
}

declare global {
  interface Window {
    S2NRI_CONFIG: S2NRIConfig
    Razorpay: new (options: RazorpayOptions) => RazorpayInstance
  }
}

export interface RazorpayOptions {
  key: string
  amount: number
  currency: string
  name: string
  description: string
  order_id: string
  prefill: { name: string; email: string }
  handler: (response: RazorpayResponse) => void
  modal: { ondismiss: () => void }
}

export interface RazorpayInstance {
  open: () => void
}

export interface RazorpayResponse {
  razorpay_payment_id: string
  razorpay_order_id: string
  razorpay_signature: string
}

// ── Settings ─────────────────────────────────────────────────────────────────
export interface Settings {
  platform_name: string
  platform_tagline: string
  platform_email: string
  platform_phone: string
  platform_us_phone: string
  platform_whatsapp: string
  platform_logo_url: string
  platform_address: string
  platform_city: string
  primary_color: string
  accent_color: string
  bank_upi: string
  bank_name: string
  bank_account_number: string
  bank_ifsc: string
  razorpay_enabled: string
  quote_validity_days: string
  hero_banners: string
  hero_heading_1: string
  hero_heading_2: string
  hero_subheading: string
  hero_description: string
  stat_1_number: string
  stat_1_label: string
  stat_2_number: string
  stat_2_label: string
  stat_3_number: string
  stat_3_label: string
  stat_4_number: string
  stat_4_label: string
  social_facebook: string
  social_twitter: string
  social_instagram: string
  social_youtube: string
  social_linkedin: string
  about_heading: string
  about_text: string
  about_video_url: string
  about_image_url: string
  home_tagline: string
  google_rating: string
  google_review_count: string
  app_playstore_url: string
  app_appstore_url: string
  seo_title: string
  seo_description: string
  [key: string]: string
}

// ── User ─────────────────────────────────────────────────────────────────────
export type StaffRole = 'super_admin' | 'manager' | 'agent' | 'finance'
export const STAFF_ROLES: StaffRole[] = ['super_admin', 'manager', 'agent', 'finance']

export interface User {
  wp_id: number
  name: string
  display_name?: string
  first_name?: string
  email: string
  s2nri_role: StaffRole | 'customer'
  is_staff: boolean
  is_customer: boolean
  customer_id: number
  phone: string
  whatsapp: string
  country: string
  is_disabled: boolean
  wp_roles: string[]
}

// ── Service ──────────────────────────────────────────────────────────────────
export interface Service {
  id: number
  slug: string
  name: string
  name_hi?: string
  short_desc: string
  description?: string
  icon?: string
  image_url?: string
  category_name?: string
  category_slug?: string
  color?: string
  turnaround?: string
  turnaround_days?: number
  price_range?: string
  required_docs?: string[]
  form_schema?: FormField[]
  is_active?: boolean
  category_icon?: string
  img?: string
  // Issues 5 & 8: saved by Service Page Builder
  hero_settings?:    Record<string, unknown> | null
  marquee_settings?: Record<string, unknown> | null
}

// ── Category ─────────────────────────────────────────────────────────────────
export interface Category {
  id: number
  slug: string
  name: string
  name_hi?: string
  icon?: string
  color?: string
  image_url?: string
  description?: string
  service_count?: number
  is_active?: boolean
}

// ── Service Section ──────────────────────────────────────────────────────────
// FIXED: 'hero' and 'marquee' removed from this type. Confirmed twice
// this session (both real SECTION_TYPES lists in the frontend, and
// ServiceDetailPage.tsx's actual render switch, which has no case for
// either) that these are not real, renderable section types — the
// actual hero/marquee rendering is driven by Service.hero_settings /
// Service.marquee_settings directly, not by items in this sections
// array. Leaving them in this type let code reference `type: 'hero'`
// as "valid" when nothing in the real renderer would ever display it.
export type SectionType =
  | 'description' | 'process' | 'documents'
  | 'why_choose' | 'trust_badges' | 'faq' | 'eligibility' | 'charges'
  | 'security' | 'benefits' | 'features' | 'cta' | 'testimonials'
  | 'text' | 'notes' | 'highlights' | 'related'

export interface TrustBadge { icon: string; value: string; label: string }
export interface WhyChooseCard { icon: string; title: string; desc: string }
export interface ProcessStep { title: string; desc: string }
export interface FaqItem { q: string; a: string }
export interface BenefitItem { icon?: string; text: string }
export interface FeatureItem { icon?: string; title: string; desc: string }
export interface TestimonialItem { name: string; location?: string; text: string; rating?: number }

export interface SectionContent {
  heading?: string
  html?: string
  steps?: ProcessStep[]
  items?: (string | FaqItem | BenefitItem | FeatureItem | TestimonialItem)[]
  badges?: TrustBadge[]
  cards?: WhyChooseCard[]
  btn?: string
  url?: string
  headline?: string
  sub?: string
  note?: string
  slugs?: string[]
}

export interface ServiceSection {
  id: number
  type: SectionType
  title: string
  content: SectionContent
  sort_order: number
  is_visible: number
  // FIXED: is_active removed - confirmed s2nri_service_sections has no
  // such column, only is_visible (Installer.php's real CREATE TABLE;
  // also confirmed via ServiceSectionAdminController::toggle()'s own
  // dead information_schema check for this exact nonexistent column,
  // audited earlier this session).
}

// ── Form Fields ──────────────────────────────────────────────────────────────
export interface FormField {
  key: string
  label: string
  label_hi?: string
  type: 'text' | 'textarea' | 'select' | 'radio' | 'number' | 'date' | 'phone' | 'file'
  required?: boolean
  options?: string[]
  placeholder?: string
  hint?: string
  description?: string
}

// ── Booking ──────────────────────────────────────────────────────────────────
// FIXED: 'pending' and 'quote_rejected' removed - neither is a real
// value in the actual s2nri_bookings.status ENUM, confirmed multiple
// times this session via direct schema reads. 'quote_rejected' is a
// real value, but it belongs to a QUOTE's own status column and is
// also a real audit-log action name — conflated here with the
// BOOKING's own status, a different column entirely. Added
// 'service_not_available', a real, actively-used value added via a
// guarded ALTER TABLE migration (Installer.php) that was missing from
// this type.
export type BookingStatus =
  | 'submitted' | 'under_review' | 'quote_sent' | 'quote_approved'
  | 'in_progress' | 'docs_requested' | 'docs_received' | 'processing'
  | 'completed' | 'cancelled' | 'on_hold' | 'service_not_available'

// FIXED: this types Booking.payment_status specifically, which should
// match bookings.payment_status's real ENUM exactly (confirmed,
// Installer.php: ENUM('pending','bank_transfer_pending','paid',
// 'refunded','waived')). The previous type mixed in 'failed'/'verified'
// — real values, but they belong to the SEPARATE s2nri_payments.status
// column, a different table entirely — included an unconfirmed
// 'partial' value, and was missing the real 'bank_transfer_pending'
// and 'waived' values.
export type PaymentStatus = 'pending' | 'bank_transfer_pending' | 'paid' | 'refunded' | 'waived'

export interface Message {
  id: number
  message: string
  sender_type: 'customer' | 'staff' | 'system'
  sender_name?: string
  created_at: string
}

export interface BookingDocument {
  id: number
  doc_type: string
  file_url: string
  file_name: string
  mime_type: string
}

export interface Quote {
  id: number
  amount: string
  notes?: string
  status: 'pending' | 'approved' | 'rejected'
  valid_until: string
}

export interface Booking {
  id: number
  booking_ref: string
  service_name: string
  service_id: number
  category_name?: string
  category_icon?: string
  status: BookingStatus
  payment_status: PaymentStatus
  quoted_amount?: string
  created_at: string
  updated_at: string
  customer_id: number
  unread_count?: number
  has_review?: boolean
  messages?: Message[]
  documents?: BookingDocument[]
  quotes?: Quote[]
  __uploadWarning?: string
}

// ── Blog ─────────────────────────────────────────────────────────────────────
export interface BlogPost {
  id: number
  slug: string
  title: string
  excerpt?: string
  content?: string
  category?: string
  date?: string
  created_at?: string
  image_url?: string
  img?: string
  read_time?: string
  is_published?: boolean
  body?: string
}

// ── Testimonial ──────────────────────────────────────────────────────────────
export interface Testimonial {
  id: number
  name: string
  location?: string
  loc?: string
  image_url?: string
  text?: string
  review?: string
  rating: number
  is_active?: boolean
}

// ── Pricing ──────────────────────────────────────────────────────────────────
export interface PricingPlan {
  id: number
  name: string
  subtitle?: string
  price: string
  price_note?: string
  popular?: boolean
  color?: string
  features: string[]
}

// ── Support ──────────────────────────────────────────────────────────────────
export interface Ticket {
  id: number
  subject: string
  message?: string
  status: 'open' | 'in_progress' | 'resolved' | 'closed'
  created_at: string
}

// ── City ─────────────────────────────────────────────────────────────────────
export interface City {
  id: number
  slug: string
  name: string
  image_url?: string
}

// ── FAQ ──────────────────────────────────────────────────────────────────────
export interface FAQ {
  id: number
  q?: string
  a?: string
  question?: string
  answer?: string
  category?: string
}

// ── Profile ──────────────────────────────────────────────────────────────────
export interface UserProfile {
  name: string
  email: string
  profile?: {
    phone?: string
    whatsapp?: string
    country?: string
    city_abroad?: string
    city_india?: string
    address_india?: string
  }
}

// ── API ───────────────────────────────────────────────────────────────────────
export interface ApiError {
  message: string
  fields?: Record<string, string>
  status: number
}

// ── Nav ──────────────────────────────────────────────────────────────────────
export interface NavItem {
  label: string
  key: string
  link?: string
  cols?: NavCol[]
}

export interface NavCol {
  heading: string
  items: Array<{ label: string; slug: string }>
}

// ── Currency / Country ────────────────────────────────────────────────────────
export interface Currency {
  code: string
  flag: string
  symbol: string
}

export interface CountryDialCode {
  code: string
  flag: string
  name: string
}

// ── Notification ─────────────────────────────────────────────────────────────
export interface Notification {
  id: number
  message: string
  is_read: '0' | '1'
  created_at: string
  link?: string
}
