import { useState } from 'react';
import type { FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { errorMessage } from '@/api/client';
import { useLogin } from '@/api/queries/auth';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { IconAlert, IconMail } from '@/components/ui/icons';
import { t } from '@/lib/i18n';

export function LoginPage() {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const login = useLogin();
    const navigate = useNavigate();

    const onSubmit = (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        login.mutate(
            { email, password },
            {
                onSuccess: () => navigate('/', { replace: true }),
                onError: (err) => setError(errorMessage(err)),
            },
        );
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 p-4">
            <div className="w-full max-w-sm rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
                <div className="mb-6 flex flex-col items-center gap-3 text-center">
                    <span className="flex size-12 items-center justify-center rounded-xl bg-indigo-600 text-white">
                        <IconMail className="size-6" />
                    </span>
                    <div>
                        <h1 className="text-lg font-bold text-slate-800">{t.appName}</h1>
                        <p className="mt-0.5 text-sm text-slate-500">{t.auth.subtitle}</p>
                    </div>
                </div>

                {error && (
                    <div className="mb-4 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-sm font-medium text-red-700">
                        <IconAlert className="mt-0.5 size-4 shrink-0" />
                        {error}
                    </div>
                )}

                <form onSubmit={onSubmit} className="space-y-4">
                    <Input
                        label={t.auth.email}
                        type="email"
                        dir="ltr"
                        required
                        autoComplete="email"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                    />
                    <Input
                        label={t.auth.password}
                        type="password"
                        dir="ltr"
                        required
                        autoComplete="current-password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                    />
                    <Button type="submit" className="w-full" loading={login.isPending}>
                        {login.isPending ? t.auth.checking : t.auth.submit}
                    </Button>
                </form>
            </div>
        </div>
    );
}
