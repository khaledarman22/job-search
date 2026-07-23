import { NavLink, Outlet } from 'react-router-dom';
import { useLogout, useMe } from '@/api/queries/auth';
import { SendingStatusStrip } from '@/components/shared/SendingStatusStrip';
import {
    IconBriefcase,
    IconHome,
    IconLogout,
    IconMail,
    IconQueue,
    IconSearch,
    IconSend,
    IconSettings,
    IconUsers,
} from '@/components/ui/icons';
import { t } from '@/lib/i18n';

const navItems = [
    { to: '/', label: t.nav.overview, Icon: IconHome, end: true },
    { to: '/jobs', label: t.nav.jobs, Icon: IconBriefcase, end: false },
    { to: '/contacts', label: t.nav.contacts, Icon: IconUsers, end: false },
    { to: '/queue', label: t.nav.queue, Icon: IconQueue, end: false },
    { to: '/sent', label: t.nav.sent, Icon: IconSend, end: false },
    { to: '/searches', label: t.nav.searches, Icon: IconSearch, end: false },
    { to: '/settings', label: t.nav.settings, Icon: IconSettings, end: false },
];

/** الهيكل العام: شريط جانبي يمين + شريط علوي + المحتوى. */
export function Layout() {
    const { data: user } = useMe();
    const logout = useLogout();

    return (
        <div className="min-h-screen">
            <aside className="fixed inset-y-0 start-0 z-30 flex w-60 flex-col border-e border-slate-200 bg-white">
                <div className="flex items-center gap-2.5 border-b border-slate-100 px-5 py-4">
                    <span className="flex size-9 items-center justify-center rounded-lg bg-indigo-600 text-white">
                        <IconMail className="size-5" />
                    </span>
                    <div>
                        <p className="text-sm font-bold text-slate-800">{t.appName}</p>
                        <p className="text-[11px] text-slate-400">{t.tagline}</p>
                    </div>
                </div>

                <nav className="grow space-y-1 overflow-y-auto p-3">
                    {navItems.map(({ to, label, Icon, end }) => (
                        <NavLink
                            key={to}
                            to={to}
                            end={end}
                            className={({ isActive }) =>
                                `flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-semibold transition-colors ${
                                    isActive
                                        ? 'bg-indigo-50 text-indigo-700'
                                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-800'
                                }`
                            }
                        >
                            <Icon className="size-4.5 shrink-0" />
                            {label}
                        </NavLink>
                    ))}
                </nav>

                <div className="border-t border-slate-100 p-3">
                    <div className="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5">
                        <div className="min-w-0">
                            <p className="truncate text-sm font-bold text-slate-700">{user?.name}</p>
                            <p className="truncate text-[11px] text-slate-400" dir="ltr">
                                {user?.email}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => logout.mutate()}
                            title={t.nav.logout}
                            className="shrink-0 rounded-md p-2 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600"
                        >
                            <IconLogout className="size-4" />
                        </button>
                    </div>
                </div>
            </aside>

            <div className="ms-60 flex min-h-screen flex-col">
                <header className="sticky top-0 z-20 flex h-14 items-center justify-between gap-4 border-b border-slate-200 bg-white/90 px-6 backdrop-blur">
                    <SendingStatusStrip />
                </header>
                <main className="mx-auto w-full max-w-7xl grow p-6">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
