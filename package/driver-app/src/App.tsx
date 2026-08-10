import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import { OfflineBanner } from './components/OfflineBanner';
import { LoginPage } from './pages/LoginPage';
import { VerifyOtpPage } from './pages/VerifyOtpPage';
import { KycPage } from './pages/KycPage';
import { TrainingPage } from './pages/TrainingPage';
import { DriverHomePage } from './pages/DriverHomePage';
import { OfferPage } from './pages/OfferPage';
import { TripPage } from './pages/TripPage';
import { EarningsPage } from './pages/EarningsPage';

function RequireAuth({ children }: { children: React.ReactNode }) {
  const { isAuthenticated } = useAuth();
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  return <>{children}</>;
}

function AppRoutes() {
  const { isAuthenticated } = useAuth();
  return (
    <Routes>
      <Route path="/login" element={isAuthenticated ? <Navigate to="/home" replace /> : <LoginPage />} />
      <Route path="/verify" element={<VerifyOtpPage />} />
      <Route path="/kyc" element={<RequireAuth><KycPage /></RequireAuth>} />
      <Route path="/training" element={<RequireAuth><TrainingPage /></RequireAuth>} />
      <Route path="/home" element={<RequireAuth><DriverHomePage /></RequireAuth>} />
      <Route path="/offer/:offerId" element={<RequireAuth><OfferPage /></RequireAuth>} />
      <Route path="/trip/:bookingId" element={<RequireAuth><TripPage /></RequireAuth>} />
      <Route path="/earnings" element={<RequireAuth><EarningsPage /></RequireAuth>} />
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
