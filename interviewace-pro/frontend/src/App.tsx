import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider } from '@/context/AuthContext';
import ProtectedRoute from '@/components/ProtectedRoute';
import Login from '@/pages/Login';
import Register from '@/pages/Register';
import VerifyOtp from '@/pages/VerifyOtp';
import ForgotPassword from '@/pages/ForgotPassword';
import AuthCallback from '@/pages/AuthCallback';
import Dashboard from '@/pages/Dashboard';
import InterviewSetup from '@/pages/InterviewSetup';
import LiveInterview from '@/pages/LiveInterview';
import Report from '@/pages/Report';
import ReportByInterview from '@/pages/ReportByInterview';
import Onboarding from '@/pages/Onboarding';
import Settings from '@/pages/Settings';
import Billing from '@/pages/Billing';
import History from '@/pages/History';
import Gamification from '@/pages/Gamification';
import Ats from '@/pages/Ats';
import Library from '@/pages/Library';
import Review from '@/pages/Review';

export default function App() {
  return (
    <BrowserRouter basename="/app">
      <AuthProvider>
        <div className="ia-app-shell">
          <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/register" element={<Register />} />
          <Route path="/verify-otp" element={<VerifyOtp />} />
          <Route path="/forgot-password" element={<ForgotPassword />} />
          <Route path="/auth/callback" element={<AuthCallback />} />

          <Route path="/onboarding" element={<ProtectedRoute><Onboarding /></ProtectedRoute>} />
          <Route path="/dashboard" element={<ProtectedRoute><Dashboard /></ProtectedRoute>} />
          <Route path="/interview/new" element={<ProtectedRoute><InterviewSetup /></ProtectedRoute>} />
          <Route path="/interview/:id" element={<ProtectedRoute><LiveInterview /></ProtectedRoute>} />
          <Route path="/report/:id" element={<ProtectedRoute><Report /></ProtectedRoute>} />
          <Route path="/history" element={<ProtectedRoute><History /></ProtectedRoute>} />
          <Route path="/history/:interviewId" element={<ProtectedRoute><ReportByInterview /></ProtectedRoute>} />
          <Route path="/settings" element={<ProtectedRoute><Settings /></ProtectedRoute>} />
          <Route path="/billing" element={<ProtectedRoute><Billing /></ProtectedRoute>} />
          <Route path="/progress" element={<ProtectedRoute><Gamification /></ProtectedRoute>} />
          <Route path="/ats" element={<ProtectedRoute><Ats /></ProtectedRoute>} />
          <Route path="/library" element={<ProtectedRoute><Library /></ProtectedRoute>} />
          <Route path="/library/review" element={<ProtectedRoute><Review /></ProtectedRoute>} />

          <Route path="*" element={<Navigate to="/dashboard" replace />} />
          </Routes>
        </div>
      </AuthProvider>
    </BrowserRouter>
  );
}
