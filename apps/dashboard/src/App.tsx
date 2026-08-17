import { Navigate, Route, Routes } from 'react-router-dom';
import ProtectedRoute from './components/ProtectedRoute';
import Layout from './components/Layout';
import Login from './pages/Login';
import AccountSetup from './pages/AccountSetup';
import Overview from './pages/Overview';
import Websites from './pages/Websites';
import WebsiteDetail from './pages/WebsiteDetail';
import ApiTokens from './pages/ApiTokens';
import Settings from './pages/Settings';
import PluginReleases from './pages/PluginReleases';
import Users from './pages/Users';

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/account-setup/:token" element={<AccountSetup />} />
      <Route
        element={
          <ProtectedRoute>
            <Layout />
          </ProtectedRoute>
        }
      >
        <Route path="/" element={<Overview />} />
        <Route path="/websites" element={<Websites />} />
        <Route path="/websites/:uuid" element={<WebsiteDetail />} />
        <Route
          path="/plugin-releases"
          element={
            <ProtectedRoute ownerOnly>
              <PluginReleases />
            </ProtectedRoute>
          }
        />
        <Route
          path="/users"
          element={
            <ProtectedRoute ownerOnly>
              <Users />
            </ProtectedRoute>
          }
        />
        <Route path="/api-tokens" element={<ApiTokens />} />
        <Route path="/settings" element={<Settings />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
