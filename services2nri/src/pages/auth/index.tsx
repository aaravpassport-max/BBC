/**
 * Auth pages — exact match of compiled components:
 *   Kn → LoginPage    (OTP + password tabs, redirect support)
 *   Xn → RegisterPage (name, email, phone, country, optional password)
 *   Qn → ForgotPasswordPage (2-step: send email → enter OTP + new password)
 *   De → AuthGuard   (redirects to dashboard if already logged in)
 */

import React, { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { AuthLayout } from '@/components/layout/Layout'
import { FormInput, Button, Alert } from '@/components/ui'
import { useStore } from '@/lib/store'
import { useT } from '@/lib/i18n'
import { api } from '@/lib/api'
import { STAFF_ROLES, COUNTRIES } from '@/lib/constants'
import type { User } from '@/types'

// ── AuthGuard (De component) ──────────────────────────────────────────────────
export function AuthGuard({ children }: { children: React.ReactNode }) {
  const user = useStore((s) => s.user)
  const nav  = useNavigate()

  if (user) {
    const dest = STAFF_ROLES.includes(user.s2nri_role) ? '/admin' : '/dashboard'
    // Use useEffect to navigate to avoid render-time navigation
    React.useEffect(() => { nav(dest, { replace: true }) }, [])
    return null
  }

  return <>{children}</>
}

// ── LoginPage (Kn component) ──────────────────────────────────────────────────
export function LoginPage() {
  const { t }     = useT()
  const nav       = useNavigate()
  const [params]  = useSearchParams()
  const redirect  = params.get('redirect')

  const sendOtp    = useStore((s) => s.sendOtp)
  const verifyOtp  = useStore((s) => s.verifyOtp)
  const login      = useStore((s) => s.login)
  const verify2fa  = useStore((s) => s.verify2fa)
  const authLoading= useStore((s) => s.authLoading)

  const primary = (window.S2NRI_CONFIG?.settings?.primary_color) || '#1E2D40'

  const [mode,     setMode]     = useState<'otp' | 'password'>('otp')
  const [step,     setStep]     = useState(1)
  const [email,    setEmail]    = useState('')
  const [otp,      setOtp]      = useState('')
  const [password, setPassword] = useState('')
  const [errorMsg, setErrorMsg] = useState('')
  const [infoMsg,  setInfoMsg]  = useState('')
  // ADDED: tracks whether the account just authenticated with a
  // password requires a second factor before the login is complete.
  const [needs2fa, setNeeds2fa] = useState(false)
  const [code2fa,  setCode2fa]  = useState('')

  function goTo(user: User) {
    const dest = redirect || (STAFF_ROLES.includes(user.s2nri_role) ? '/admin' : '/dashboard')
    nav(dest, { replace: true })
  }

  async function handleSendOtp() {
    setErrorMsg('')
    if (!email.trim()) { setErrorMsg('Please enter your email address.'); return }
    const res = await sendOtp(email.trim(), 'login')
    if (res.success) {
      setStep(2)
      setInfoMsg(`OTP sent to ${email}. Check your inbox (and spam folder).`)
    } else {
      setErrorMsg(res.message || 'Failed to send OTP.')
    }
  }

  async function handleVerifyOtp() {
    setErrorMsg(''); setInfoMsg('')
    if (otp.length !== 6) { setErrorMsg('Please enter the 6-digit OTP.'); return }
    const res = await verifyOtp(email.trim(), otp, 'login')
    if (res.success && res.user) goTo(res.user)
    else setErrorMsg(res.message || 'Invalid OTP.')
  }

  async function handlePasswordLogin() {
    setErrorMsg('')
    if (!email.trim() || !password) { setErrorMsg('Email and password are required.'); return }
    const res = await login(email.trim(), password)
    if (res.requires_2fa) {
      setNeeds2fa(true)
      setInfoMsg('Your account requires a verification code. Check your email.')
    } else if (res.success && res.user) {
      goTo(res.user)
    } else {
      setErrorMsg(res.message || 'Login failed.')
    }
  }

  async function handleVerify2fa() {
    setErrorMsg('')
    if (code2fa.length !== 6) { setErrorMsg('Please enter the 6-digit code.'); return }
    const res = await verify2fa(email.trim(), code2fa)
    if (res.success && res.user) goTo(res.user)
    else setErrorMsg(res.message || 'Invalid code.')
  }

  return (
    <AuthGuard>
      <AuthLayout title="Welcome back" subtitle="Sign in to your account">
        {/* Mode tabs */}
        <div style={{ display: 'flex', background: '#f3f4f6', borderRadius: 10, padding: 4, marginBottom: 24 }}>
          {(['otp', 'password'] as const).map((m, i) => (
            <button
              key={m}
              onClick={() => { setMode(m); setStep(1); setErrorMsg(''); setInfoMsg(''); setOtp('') }}
              style={{ flex: 1, padding: 8, border: 'none', borderRadius: 8, cursor: 'pointer', fontWeight: mode === m ? 700 : 500, background: mode === m ? '#fff' : 'transparent', fontSize: 14, boxShadow: mode === m ? '0 1px 3px rgba(0,0,0,.1)' : 'none', transition: 'all .15s' }}
            >
              {i === 0 ? 'OTP Login' : 'Password'}
            </button>
          ))}
        </div>

        <Alert type="error"   message={errorMsg} onClose={() => setErrorMsg('')} />
        <Alert type="success" message={infoMsg} />

        {mode === 'otp' ? (
          <>
            <FormInput
              label={t('email_address')}
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="you@example.com"
              required
              disabled={step === 2}
              onKeyDown={(e) => e.key === 'Enter' && step === 1 && handleSendOtp()}
            />
            {step === 2 && (
              <FormInput
                label={t('otp_code')}
                type="text"
                inputMode="numeric"
                pattern="[0-9]{6}"
                maxLength={6}
                value={otp}
                onChange={(e) => setOtp(e.target.value.replace(/\D/g, ''))}
                placeholder="Enter OTP"
                required
                autoFocus
                hint={`OTP sent to ${email}. Valid for 10 minutes.`}
                onKeyDown={(e) => e.key === 'Enter' && handleVerifyOtp()}
              />
            )}
            {step === 1 ? (
              <Button onClick={handleSendOtp} loading={authLoading} disabled={!email.trim()} style={{ width: '100%', justifyContent: 'center' }}>
                {t('send_otp')}
              </Button>
            ) : (
              <div style={{ display: 'flex', gap: 10 }}>
                <Button onClick={handleVerifyOtp} loading={authLoading} style={{ flex: 1, justifyContent: 'center' }}>
                  {t('verify_sign_in')}
                </Button>
                <Button variant="ghost" onClick={() => { setStep(1); setOtp(''); setInfoMsg('') }} disabled={authLoading}>
                  {t('resend')}
                </Button>
              </div>
            )}
          </>
        ) : needs2fa ? (
          <>
            <FormInput
              label="Verification Code"
              type="text"
              inputMode="numeric"
              pattern="[0-9]{6}"
              maxLength={6}
              value={code2fa}
              onChange={(e) => setCode2fa(e.target.value.replace(/\D/g, ''))}
              placeholder="Enter the 6-digit code"
              required
              autoFocus
              hint={`Code sent to ${email}. Valid for 10 minutes.`}
              onKeyDown={(e) => e.key === 'Enter' && handleVerify2fa()}
            />
            <Button onClick={handleVerify2fa} loading={authLoading} style={{ width: '100%', justifyContent: 'center' }}>
              Verify and Sign In
            </Button>
          </>
        ) : (
          <>
            <FormInput
              label={t('email_address')}
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="you@example.com"
              required
            />
            <FormInput
              label={t('password_login')}
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Your password"
              required
              onKeyDown={(e) => e.key === 'Enter' && handlePasswordLogin()}
            />
            <div style={{ textAlign: 'right', marginTop: -8, marginBottom: 16 }}>
              <Link to="/forgot-password" style={{ fontSize: 13, color: primary, textDecoration: 'none' }}>
                Forgot password?
              </Link>
            </div>
            <Button onClick={handlePasswordLogin} loading={authLoading} disabled={!email.trim() || !password} style={{ width: '100%', justifyContent: 'center' }}>
              {t('sign_in')}
            </Button>
          </>
        )}

        <p style={{ textAlign: 'center', marginTop: 20, fontSize: 14, color: '#6b7280' }}>
          Don't have an account?{' '}
          <Link to="/register" style={{ color: primary, fontWeight: 600, textDecoration: 'none' }}>Create one free</Link>
        </p>
      </AuthLayout>
    </AuthGuard>
  )
}

// ── RegisterPage (Xn component) ───────────────────────────────────────────────
export function RegisterPage() {
  const { t }    = useT()
  const nav      = useNavigate()
  const register = useStore((s) => s.register)
  const loading  = useStore((s) => s.authLoading)
  const primary  = (window.S2NRI_CONFIG?.settings?.primary_color) || '#1E2D40'

  const [form,     setForm]     = useState({ name: '', email: '', phone: '', country: '', password: '' })
  const [errors,   setErrors]   = useState<Record<string, string>>({})
  const [errorMsg, setErrorMsg] = useState('')

  function set(field: string) {
    return (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
      setForm((f) => ({ ...f, [field]: e.target.value }))
  }

  function validate(): Record<string, string> {
    const errs: Record<string, string> = {}
    if (!form.name.trim() || form.name.trim().length < 2) errs.name = 'Full name is required (min 2 chars).'
    if (!form.email.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) errs.email = 'Valid email is required.'
    if (!form.country) errs.country = 'Please select your country.'
    if (form.password && form.password.length < 8) errs.password = 'Password must be at least 8 characters.'
    return errs
  }

  async function handleSubmit() {
    const errs = validate()
    if (Object.keys(errs).length) { setErrors(errs); return }
    setErrors({}); setErrorMsg('')

    const res = await register({ ...form, name: form.name.trim(), email: form.email.trim() })
    if (res.success) {
      nav('/dashboard', { replace: true })
    } else {
      setErrorMsg(res.message || 'Registration failed.')
    }
  }

  return (
    <AuthGuard>
      <AuthLayout title="Create your account" subtitle="Start managing your India affairs today — free">
        <Alert type="error" message={errorMsg} onClose={() => setErrorMsg('')} />

        <FormInput label={t('full_name')}      value={form.name}     onChange={set('name')}     placeholder="Rajesh Kumar"        required error={errors.name} />
        <FormInput label={t('email_address')}  type="email" value={form.email} onChange={set('email')} placeholder="rajesh@example.com" required error={errors.email} />
        <FormInput label="Phone / WhatsApp"    type="tel"   value={form.phone} onChange={set('phone')} placeholder="+1-555-0123" hint="Include country code — for status updates" />

        {/* Country select */}
        <div style={{ marginBottom: 16 }}>
          <label style={{ display: 'block', fontWeight: 600, marginBottom: 6, fontSize: 14, color: '#374151' }}>
            Country of Residence <span aria-hidden="true" style={{ color: '#dc2626' }}>*</span>
          </label>
          <select
            value={form.country}
            onChange={set('country')}
            required
            style={{ width: '100%', padding: '10px 14px', border: `1px solid ${errors.country ? '#fca5a5' : '#d1d5db'}`, borderRadius: 8, fontSize: 15, background: '#fff', boxSizing: 'border-box' as const }}
          >
            <option value="">Select your country</option>
            {COUNTRIES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
          {errors.country && <p role="alert" style={{ margin: '4px 0 0', fontSize: 12, color: '#b91c1c' }}>{errors.country}</p>}
        </div>

        <FormInput
          label={t('password_optional')}
          type="password"
          value={form.password}
          onChange={set('password')}
          placeholder="Leave blank — you can always log in via OTP"
          hint="If blank, use OTP login. Password enables direct sign-in."
          error={errors.password}
        />

        <Button onClick={handleSubmit} loading={loading} style={{ width: '100%', justifyContent: 'center', padding: 13 }}>
          {t('create_account')}
        </Button>

        <p style={{ textAlign: 'center', marginTop: 16, fontSize: 14, color: '#6b7280' }}>
          Already have an account?{' '}
          <Link to="/login" style={{ color: primary, fontWeight: 600, textDecoration: 'none' }}>Sign in</Link>
        </p>
      </AuthLayout>
    </AuthGuard>
  )
}

// ── ForgotPasswordPage (Qn component) ────────────────────────────────────────
export function ForgotPasswordPage() {
  const nav     = useNavigate()
  const primary = (window.S2NRI_CONFIG?.settings?.primary_color) || '#1E2D40'

  const [step,     setStep]     = useState(1)
  const [email,    setEmail]    = useState('')
  const [otp,      setOtp]      = useState('')
  const [password, setPassword] = useState('')
  const [errorMsg, setErrorMsg] = useState('')
  const [infoMsg,  setInfoMsg]  = useState('')
  const [loading,  setLoading]  = useState(false)

  async function sendCode() {
    if (!email.trim()) { setErrorMsg('Please enter your email.'); return }
    setErrorMsg(''); setLoading(true)
    try {
      await api.post('auth/forgot-password', { email: email.trim() })
    } catch {}
    // Always advance (don't reveal if email exists)
    setStep(2)
    setInfoMsg(`If an account exists for ${email}, a reset code has been sent.`)
    setLoading(false)
  }

  async function resetPassword() {
    if (otp.length !== 6)      { setErrorMsg('Please enter the 6-digit code.'); return }
    if (password.length < 8)   { setErrorMsg('Password must be at least 8 characters.'); return }
    setErrorMsg(''); setLoading(true)
    try {
      await api.post('auth/reset-password', { email: email.trim(), otp, new_password: password })
      setInfoMsg('Password reset successfully! Redirecting to login…')
      setTimeout(() => nav('/login'), 2000)
    } catch (e: unknown) {
      setErrorMsg((e as { message: string }).message)
    }
    setLoading(false)
  }

  return (
    <AuthGuard>
      <AuthLayout title="Reset Password" subtitle="Enter your email and we'll send a reset code">
        <Alert type="error"   message={errorMsg} onClose={() => setErrorMsg('')} />
        <Alert type="success" message={infoMsg} />

        {step === 1 ? (
          <>
            <FormInput
              label="Email Address"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="you@example.com"
              required
              onKeyDown={(e) => e.key === 'Enter' && sendCode()}
            />
            <Button onClick={sendCode} loading={loading} disabled={!email.trim()} style={{ width: '100%', justifyContent: 'center' }}>
              Send Reset Code
            </Button>
          </>
        ) : (
          <>
            <FormInput
              label="Reset Code (OTP)"
              type="text"
              inputMode="numeric"
              maxLength={6}
              value={otp}
              onChange={(e) => setOtp(e.target.value.replace(/\D/g, ''))}
              placeholder="6-digit code from email"
              required
              autoFocus
            />
            <FormInput
              label="New Password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Minimum 8 characters"
              required
              onKeyDown={(e) => e.key === 'Enter' && resetPassword()}
            />
            <Button onClick={resetPassword} loading={loading} disabled={otp.length < 6 || password.length < 8} style={{ width: '100%', justifyContent: 'center' }}>
              Reset Password
            </Button>
          </>
        )}

        <p style={{ textAlign: 'center', marginTop: 16, fontSize: 14, color: '#6b7280' }}>
          <Link to="/login" style={{ color: primary, fontWeight: 600, textDecoration: 'none' }}>← Back to Login</Link>
        </p>
      </AuthLayout>
    </AuthGuard>
  )
}
