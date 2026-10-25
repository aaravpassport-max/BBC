import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import { OfflineBanner } from './components/OfflineBanner';
import { LoginPage } from './pages/LoginPage';
import { VerifyOtpPage } from './pages/VerifyOtpPage';
import { MyAccountsPage } from './pages/MyAccountsPage';
import { AccountDashboardPage } from './pages/AccountDashboardPage';
import { InvoicesPage } from './pages/InvoicesPage';
import { InvoiceDetailPage } from './pages/InvoiceDetailPage';

function RequireAuth({ children }: { children: React.ReactNode }) {
  const { isAuthenticated } = useAuth();
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  return <>{children}</>;
}

function AppRoutes() {
  const { isAuthenticated } = useAuth();
  return (
    <Routes>
      <Route path="/login" element={isAuthenticated ? <Navigate to="/accounts" replace /> : <LoginPage />} />
      <Route path="/verify" element={<VerifyOtpPage />} />
      <Route path="/accounts" element={<RequireAuth><MyAccountsPage /></RequireAuth>} />
      <Route path="/accounts/:accountId" element={<RequireAuth><AccountDashboardPage /></RequireAuth>} />
      <Route path="/accounts/:accountId/invoices" element={<RequireAuth><InvoicesPage /></RequireAuth>} />
      <Route path="/accounts/:accountId/invoices/:invoiceId" element={<RequireAuth><InvoiceDetailPage /></RequireAuth>} />
      <Route path="*" element={<Navigate to={isAuthenticated ? '/accounts' : '/login'} replace />} />
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
