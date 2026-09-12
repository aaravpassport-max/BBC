import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import { OfflineBanner } from './components/OfflineBanner';
import { LoginPage } from './pages/LoginPage';
import { VerifyOtpPage } from './pages/VerifyOtpPage';
import { SosQueuePage } from './pages/SosQueuePage';
import { DispatchMonitorPage } from './pages/DispatchMonitorPage';

function RequireAuth({ children }: { children: React.ReactNode }) {
  const { isAuthenticated } = useAuth();
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  return <>{children}</>;
}

function AppRoutes() {
  const { isAuthenticated } = useAuth();
  return (
    <Routes>
      <Route path="/login" element={isAuthenticated ? <Navigate to="/sos" replace /> : <LoginPage />} />
      <Route path="/verify" element={<VerifyOtpPage />} />
      <Route path="/sos" element={<RequireAuth><SosQueuePage /></RequireAuth>} />
      <Route path="/dispatch" element={<RequireAuth><DispatchMonitorPage /></RequireAuth>} />
      <Route path="*" element={<Navigate to={isAuthenticated ? '/sos' : '/login'} replace />} />
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
