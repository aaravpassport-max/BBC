import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import { AppShell } from './components/AppShell';
import { OfflineBanner } from './components/OfflineBanner';
import { LoginPage } from './pages/LoginPage';
import { VerifyOtpPage } from './pages/VerifyOtpPage';
import { KycPage } from './pages/KycPage';
import { TrainingPage } from './pages/TrainingPage';
import { DriverHomePage } from './pages/DriverHomePage';
import { OfferPage } from './pages/OfferPage';
import { TripPage } from './pages/TripPage';
import { EarningsPage } from './pages/EarningsPage';
import { HistoryPage } from './pages/HistoryPage';
import { ProfilePage } from './pages/ProfilePage';
import { VehiclePage } from './pages/VehiclePage';
import { PenaltiesPage } from './pages/PenaltiesPage';
import { HelpPage } from './pages/HelpPage';
import { SupportPage } from './pages/SupportPage';
import { NewSupportTicketPage } from './pages/NewSupportTicketPage';
import { SupportTicketPage } from './pages/SupportTicketPage';
import { NotificationsPage } from './pages/NotificationsPage';
import { SettingsPage } from './pages/SettingsPage';
import { ReferralPage } from './pages/ReferralPage';
import { EditProfilePage } from './pages/EditProfilePage';
import { usePushRegistration } from './hooks/usePushRegistration';
import { useSessionKeepAlive } from './hooks/useSessionKeepAlive';

function PushRegistration() {
  usePushRegistration();
  return null;
}

function SessionKeepAlive() {
  useSessionKeepAlive();
  return null;
}

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
      <Route path="/kyc" element={<RequireAuth><KycPage /></RequireAuth>} />
      <Route path="/training" element={<RequireAuth><TrainingPage /></RequireAuth>} />
      <Route path="/home" element={<AuthenticatedLayout><DriverHomePage /></AuthenticatedLayout>} />
      <Route path="/history" element={<AuthenticatedLayout><HistoryPage /></AuthenticatedLayout>} />
      <Route path="/earnings" element={<AuthenticatedLayout><EarningsPage /></AuthenticatedLayout>} />
      <Route path="/profile" element={<AuthenticatedLayout><ProfilePage /></AuthenticatedLayout>} />
      <Route path="/profile/edit" element={<RequireAuth><EditProfilePage /></RequireAuth>} />
      <Route path="/vehicle" element={<RequireAuth><VehiclePage /></RequireAuth>} />
      <Route path="/penalties" element={<RequireAuth><PenaltiesPage /></RequireAuth>} />
      <Route path="/help" element={<RequireAuth><HelpPage /></RequireAuth>} />
      <Route path="/support" element={<RequireAuth><SupportPage /></RequireAuth>} />
      <Route path="/support/new" element={<RequireAuth><NewSupportTicketPage /></RequireAuth>} />
      <Route path="/support/:ticketId" element={<RequireAuth><SupportTicketPage /></RequireAuth>} />
      <Route path="/notifications" element={<RequireAuth><NotificationsPage /></RequireAuth>} />
      <Route path="/settings" element={<RequireAuth><SettingsPage /></RequireAuth>} />
      <Route path="/referral" element={<RequireAuth><ReferralPage /></RequireAuth>} />
      <Route path="/offer/:offerId" element={<RequireAuth><OfferPage /></RequireAuth>} />
      <Route path="/trip/:bookingId" element={<RequireAuth><TripPage /></RequireAuth>} />
      <Route path="*" element={<Navigate to={isAuthenticated ? '/home' : '/login'} replace />} />
    </Routes>
  );
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <OfflineBanner />
        <PushRegistration />
        <SessionKeepAlive />
        <AppRoutes />
      </BrowserRouter>
    </AuthProvider>
  );
}
