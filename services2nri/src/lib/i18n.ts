/**
 * i18n — matches xe() (useLangSwitch), ce() (useT), and ne{} (translations)
 * from booking-ChxRsZYG.js
 */

import { useLang } from './store'

// Translation dictionary — exact strings from compiled bundle
const translations: Record<'en' | 'hi', Record<string, string>> = {
  en: {
    dashboard: 'Dashboard',
    my_bookings: 'My Bookings',
    messages: 'Messages',
    send: 'Send',
    recent_bookings: 'Recent Bookings',
    quick_actions: 'Quick Actions',
    total_bookings: 'Total Bookings',
    in_progress: 'In Progress',
    completed: 'Completed',
    email_address: 'Email Address',
    otp_code: 'OTP Code',
    send_otp: 'Send OTP',
    verify_sign_in: 'Verify & Sign In',
    resend: 'Resend',
    sign_in: 'Sign In',
    password_login: 'Password',
    full_name: 'Full Name',
    create_account: 'Create Account',
    password_optional: 'Password (optional)',
    confirm: 'Confirm',
    close: 'Close',
    loading: 'Loading…',
    no_results: 'No results found',
    view_all: 'View all →',
    admin_dashboard: 'Admin Dashboard',
    all_bookings: 'All Bookings',
    payments: 'Payments',
    customers: 'Customers',
    staff: 'Staff',
    categories: 'Categories',
    reviews: 'Reviews',
    audit_log: 'Audit Log',
    settings: 'Settings',
  },
  hi: {
    dashboard: 'डैशबोर्ड',
    my_bookings: 'मेरी बुकिंग',
    messages: 'संदेश',
    send: 'भेजें',
    recent_bookings: 'हाल की बुकिंग',
    quick_actions: 'त्वरित क्रियाएं',
    total_bookings: 'कुल बुकिंग',
    in_progress: 'प्रगति में',
    completed: 'पूर्ण',
    email_address: 'ईमेल पता',
    otp_code: 'OTP कोड',
    send_otp: 'OTP भेजें',
    verify_sign_in: 'सत्यापित करें और साइन इन करें',
    resend: 'पुनः भेजें',
    sign_in: 'साइन इन',
    password_login: 'पासवर्ड',
    full_name: 'पूरा नाम',
    create_account: 'खाता बनाएं',
    password_optional: 'पासवर्ड (वैकल्पिक)',
    confirm: 'पुष्टि करें',
    close: 'बंद करें',
    loading: 'लोड हो रहा है…',
    no_results: 'कोई परिणाम नहीं मिला',
    view_all: 'सभी देखें →',
    admin_dashboard: 'Admin Dashboard',
    all_bookings: 'All Bookings',
    payments: 'Payments',
    customers: 'Customers',
    staff: 'Staff',
    categories: 'Categories',
    reviews: 'Reviews',
    audit_log: 'Audit Log',
    settings: 'Settings',
  },
}

/**
 * useT — returns t(key, fallback) translation function
 * Matches ce() in compiled bundle
 */
export function useT() {
  const lang = useLang((s) => s.lang)
  const t = (key: string, fallback?: string): string => {
    return translations[lang]?.[key] ?? translations.en?.[key] ?? fallback ?? key
  }
  return { t, lang }
}

/**
 * useLangSwitch — returns [lang, setLang]
 * Matches xe() in compiled bundle
 */
export function useLangSwitch(): ['en' | 'hi', (lang: 'en' | 'hi') => void] {
  const lang = useLang((s) => s.lang)
  const setLang = useLang((s) => s.setLang)
  return [lang, setLang]
}
