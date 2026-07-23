import { Navigate, Route, Routes } from 'react-router-dom';
import { AuthGuard } from '@/app/AuthGuard';
import { Layout } from '@/app/Layout';
import { LoginPage } from '@/features/auth/LoginPage';
import { ContactsPage } from '@/features/contacts/ContactsPage';
import { JobDetailPage } from '@/features/jobs/JobDetailPage';
import { JobsPage } from '@/features/jobs/JobsPage';
import { OverviewPage } from '@/features/overview/OverviewPage';
import { QueuePage } from '@/features/queue/QueuePage';
import { SearchesPage } from '@/features/searches/SearchesPage';
import { SentDetailPage } from '@/features/sent/SentDetailPage';
import { SentPage } from '@/features/sent/SentPage';
import { SettingsPage } from '@/features/settings/SettingsPage';

export function AppRouter() {
    return (
        <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route
                element={
                    <AuthGuard>
                        <Layout />
                    </AuthGuard>
                }
            >
                <Route path="/" element={<OverviewPage />} />
                <Route path="/jobs" element={<JobsPage />} />
                <Route path="/jobs/:id" element={<JobDetailPage />} />
                <Route path="/contacts" element={<ContactsPage />} />
                <Route path="/queue" element={<QueuePage />} />
                <Route path="/sent" element={<SentPage />} />
                <Route path="/sent/:id" element={<SentDetailPage />} />
                <Route path="/searches" element={<SearchesPage />} />
                <Route path="/settings" element={<SettingsPage />} />
                <Route path="*" element={<Navigate to="/" replace />} />
            </Route>
        </Routes>
    );
}
