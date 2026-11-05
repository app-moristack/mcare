import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, Link, useForm } from '@inertiajs/react';
export default function Mfa({ enrolled, secret }: { enrolled: boolean; secret: string | null }) {
    const setup = useForm({ password: '' });
    const verify = useForm({ code: '' });
    return (
        <AuthLayout
            title={enrolled ? 'Verify your authenticator' : 'Secure your workforce account'}
            description="Optional authenticator security. Use an authenticator app on your own device if you choose to enrol."
        >
            <Head title="Two-factor authentication" />
            {!enrolled && !secret ? (
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        setup.post('/security/mfa/setup', { onFinish: () => setup.reset('password') });
                    }}
                >
                    <Label htmlFor="password">Confirm your password</Label>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="current-password"
                        required
                        value={setup.data.password}
                        onChange={(e) => setup.setData('password', e.target.value)}
                    />
                    <InputError message={setup.errors.password} />
                    <Button disabled={setup.processing} className="w-full">
                        Begin authenticator setup
                    </Button>
                </form>
            ) : (
                <>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            verify.post('/security/mfa/verify', { onFinish: () => verify.reset('code') });
                        }}
                    >
                        {secret && (
                            <div className="bg-muted/40 rounded-md border p-4">
                                <p className="text-sm">
                                    Add a time-based account named MauriCare to your authenticator. Enter this setup key manually (6 digits, 30-second
                                    interval):
                                </p>
                                <code className="bg-card mt-3 block rounded border p-3 text-base break-all select-all">{secret}</code>
                                <p className="text-muted-foreground mt-3 text-xs">Keep this key private. Confirm a code below to finish enrolment.</p>
                            </div>
                        )}
                        <Label htmlFor="code">6-digit authenticator code</Label>
                        <Input
                            id="code"
                            inputMode="numeric"
                            pattern="[0-9]{6}"
                            maxLength={6}
                            autoComplete="one-time-code"
                            required
                            value={verify.data.code}
                            onChange={(e) => verify.setData('code', e.target.value)}
                        />
                        <InputError message={verify.errors.code} />
                        <Button disabled={verify.processing} className="w-full">
                            Verify and continue
                        </Button>
                    </form>
                </>
            )}
            <p className="text-muted-foreground mt-5 text-xs">
                Lost your authenticator? Contact your organisation for controlled recovery; no automatic MFA reset is available.
            </p>
            <Link href="/dashboard" className="text-primary mt-5 block text-center text-sm">
                Back to account
            </Link>
        </AuthLayout>
    );
}
