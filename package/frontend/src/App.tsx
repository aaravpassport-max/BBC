import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import { AppShell } from './components/AppShell';
import { LoginPage } from './pages/LoginPage';
import { VerifyOtpPage } from './pages/VerifyOtpPage';
import { OnboardingPage } from './pages/OnboardingPage';
import { HomePage } from './pages/HomePage';
import { ConfirmPage } from './pages/ConfirmPage';
import { TrackPage } from './pages/TrackPage';
import { TripDetailPage } from './pages/TripDetailPage';
import { WalletPage } from './pages/WalletPage';
import { HistoryPage } from './pages/HistoryPage';
import { ReceiptPage } from './pages/ReceiptPage';
import { ProfilePage } from './pages/ProfilePage';
import { HelpPage } from './pages/HelpPage';
import { SupportPage } from './pages/SupportPage';
import { NewSupportTicketPage } from './pages/NewSupportTicketPage';
import { SupportTicketPage } from './pages/SupportTicketPage';
import { NotificationsPage } from './pages/NotificationsPage';
import { SettingsPage } from './pages/SettingsPage';
import { ReferralPage } from './pages/ReferralPage';
import { SubscriptionPage } from './pages/SubscriptionPage';
import { CorporatePage } from './pages/CorporatePage';
import { AddressesPage } from './pages/AddressesPage';
import { OfflineBanner } from './components/OfflineBanner';

function RequireAuth({ children }: { children: React.ReactNode }) {
  const { isAuthenticated } = useAuth();
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  return <>{children}</>;
}

function AuthenticatedLayout({ children }: { children: React.ReactNode }) {
  return (
    <RequireAuth>
      <AppShell>{children}</AppShell>
    </RequireAuth>
  );
}

function AppRoutes() {
  const { isAuthenticated } = useAuth();
  return (
    <Routes>
      <Route path="/login" element={isAuthenticated ? <Navigate to="/home" replace /> : <LoginPage />} />
      <Route path="/verify" element={<VerifyOtpPage />} />
      <Route path="/onboarding" element={<RequireAuth><OnboardingPage /></RequireAuth>} />
      <Route path="/home" element={<AuthenticatedLayout><HomePage /></AuthenticatedLayout>} />
      <Route path="/confirm" element={<RequireAuth><ConfirmPage /></RequireAuth>} />
      <Route path="/track/:bookingId" element={<RequireAuth><TrackPage /></RequireAuth>} />
      <Route path="/trip/:bookingId" element={<RequireAuth><TripDetailPage /></RequireAuth>} />
      <Route path="/wallet" element={<AuthenticatedLayout><WalletPage /></AuthenticatedLayout>} />
      <Route path="/history" element={<AuthenticatedLayout><HistoryPage /></AuthenticatedLayout>} />
      <Route path="/profile" element={<AuthenticatedLayout><ProfilePage /></AuthenticatedLayout>} />
      <Route path="/help" element={<RequireAuth><HelpPage /></RequireAuth>} />
      <Route path="/support" element={<RequireAuth><SupportPage /></RequireAuth>} />
      <Route path="/support/new" element={<RequireAuth><NewSupportTicketPage /></RequireAuth>} />
      <Route path="/support/:ticketId" element={<RequireAuth><SupportTicketPage /></RequireAuth>} />
      <Route path="/notifications" element={<RequireAuth><NotificationsPage /></RequireAuth>} />
      <Route path="/settings" element={<RequireAuth><SettingsPage /></RequireAuth>} />
      <Route path="/referral" element={<RequireAuth><ReferralPage /></RequireAuth>} />
      <Route path="/subscription" element={<RequireAuth><SubscriptionPage /></RequireAuth>} />
      <Route path="/addresses" element={<RequireAuth><AddressesPage /></RequireAuth>} />
      <Route path="/corporate" element={<RequireAuth><CorporatePage /></RequireAuth>} />
      <Route path="/receipt/:bookingId" element={<RequireAuth><ReceiptPage /></RequireAuth>} />
      <Route path="*" element={<Navigate to={isAuthenticated ? '/home' : '/login'} replace />} />
    </Routes>
  );
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <OfflineBanner />
        <AppRoutes />
      </BrowserRouter>
    </AuthProvider>
  );
}
