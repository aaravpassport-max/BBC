import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import { AppShell } from './components/AppShell';
import { LoginPage } from './pages/LoginPage';
import { VerifyOtpPage } from './pages/VerifyOtpPage';
import { HomePage } from './pages/HomePage';
import { ConfirmPage } from './pages/ConfirmPage';
import { TrackPage } from './pages/TrackPage';
import { WalletPage } from './pages/WalletPage';
import { HistoryPage } from './pages/HistoryPage';
import { ReceiptPage } from './pages/ReceiptPage';
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
      <Route path="/home" element={<AuthenticatedLayout><HomePage /></AuthenticatedLayout>} />
      <Route path="/confirm" element={<RequireAuth><ConfirmPage /></RequireAuth>} />
      <Route path="/track/:bookingId" element={<RequireAuth><TrackPage /></RequireAuth>} />
      <Route path="/wallet" element={<AuthenticatedLayout><WalletPage /></AuthenticatedLayout>} />
      <Route path="/history" element={<AuthenticatedLayout><HistoryPage /></AuthenticatedLayout>} />
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
