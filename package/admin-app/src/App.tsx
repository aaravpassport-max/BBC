import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import { OfflineBanner } from './components/OfflineBanner';
import { LoginPage } from './pages/LoginPage';
import { VerifyOtpPage } from './pages/VerifyOtpPage';
import { RateCardsPage } from './pages/RateCardsPage';
import { DriversPage } from './pages/DriversPage';
import { FraudQueuePage } from './pages/FraudQueuePage';
import { SupportPage } from './pages/SupportPage';
import { AnalyticsPage } from './pages/AnalyticsPage';
import { MarketingPage } from './pages/MarketingPage';
import { RbacPage } from './pages/RbacPage';
import { KycReviewPage } from './pages/KycReviewPage';
import { PenaltiesPage } from './pages/PenaltiesPage';
import { SettlementPage } from './pages/SettlementPage';

function RequireAuth({ children }: { children: React.ReactNode }) {
  const { isAuthenticated } = useAuth();
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  return <>{children}</>;
}

function AppRoutes() {
  const { isAuthenticated } = useAuth();
  return (
    <Routes>
      <Route path="/login" element={isAuthenticated ? <Navigate to="/rate-cards" replace /> : <LoginPage />} />
      <Route path="/verify" element={<VerifyOtpPage />} />
      <Route path="/rate-cards" element={<RequireAuth><RateCardsPage /></RequireAuth>} />
      <Route path="/drivers" element={<RequireAuth><DriversPage /></RequireAuth>} />
      <Route path="/kyc-review" element={<RequireAuth><KycReviewPage /></RequireAuth>} />
      <Route path="/penalties" element={<RequireAuth><PenaltiesPage /></RequireAuth>} />
      <Route path="/fraud-queue" element={<RequireAuth><FraudQueuePage /></RequireAuth>} />
      <Route path="/support" element={<RequireAuth><SupportPage /></RequireAuth>} />
      <Route path="/analytics" element={<RequireAuth><AnalyticsPage /></RequireAuth>} />
      <Route path="/settlement" element={<RequireAuth><SettlementPage /></RequireAuth>} />
      <Route path="/marketing" element={<RequireAuth><MarketingPage /></RequireAuth>} />
      <Route path="/rbac" element={<RequireAuth><RbacPage /></RequireAuth>} />
      <Route path="*" element={<Navigate to={isAuthenticated ? '/rate-cards' : '/login'} replace />} />
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
