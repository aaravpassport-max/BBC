import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { getConfig } from '@/config';
import { LauncherPage } from '@/apps/LauncherPage';
import { CustomerApp } from '@/apps/customer/CustomerApp';
import { DriverApp } from '@/apps/driver/DriverApp';
import { AdminApp } from '@/apps/admin/AdminApp';
import { OpsApp } from '@/apps/ops/OpsApp';

export function App() {
  const basename = getConfig().appPath.replace(/\/$/, '') || '/portmystuff';

  return (
    <BrowserRouter basename={basename}>
      <Routes>
        <Route path="/" element={<LauncherPage />} />
        <Route path="/customer/*" element={<CustomerApp />} />
        <Route path="/driver/*" element={<DriverApp />} />
        <Route path="/admin/*" element={<AdminApp />} />
        <Route path="/ops/*" element={<OpsApp />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}
