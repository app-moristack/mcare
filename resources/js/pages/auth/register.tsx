import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

type RegisterForm = {
    account_kind: string;
    preferred_language: string;
    terms_acceptance: boolean;
    privacy_acknowledgement: boolean;
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
};

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm<RegisterForm>({
        account_kind: 'self_patient',
        preferred_language: 'en',
        terms_acceptance: false,
        privacy_acknowledgement: false,
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Create an account" description="Your first step towards more connected care.">
            <Head title="Register" />
            <form className="flex flex-col gap-5" onSubmit={submit}>
                <div className="grid gap-5">
                    <div className="grid gap-2">
                        <Label htmlFor="account_kind">How will you use MauriCare?</Label>
                        <select
                            id="account_kind"
                            className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                            value={data.account_kind}
                            onChange={(e) => setData('account_kind', e.target.value)}
                            disabled={processing}
                        >
                            <option value="self_patient">Patient — care and health records</option>
                            <option value="records_only">Personal health records only</option>
                            <option value="proxy">Family member / representative</option>
                            <option value="provider_application">Apply as a care professional</option>
                            <option value="partner_application">Apply as a partner</option>
                        </select>
                        <InputError message={errors.account_kind} />
                        <p className="text-muted-foreground text-xs">
                            Professional and partner applications require verification and approval. Family access requires separate patient
                            authority.
                        </p>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="preferred_language">Preferred language</Label>
                        <select
                            id="preferred_language"
                            className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                            value={data.preferred_language}
                            onChange={(e) => setData('preferred_language', e.target.value)}
                            disabled={processing}
                        >
                            <option value="en">English</option>
                            <option value="fr">Français</option>
                            <option value="mfe">Kreol Morisien</option>
                        </select>
                        <p className="text-muted-foreground text-xs">We’ll save your preference. This initial version is in English.</p>
                        <InputError message={errors.preferred_language} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            type="text"
                            required
                            autoFocus
                            autoComplete="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            disabled={processing}
                            placeholder="Full name"
                        />
                        <InputError message={errors.name} className="mt-2" />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            autoComplete="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            disabled={processing}
                            placeholder="email@example.com"
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">Password</Label>
                        <Input
                            id="password"
                            type="password"
                            required
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            disabled={processing}
                            minLength={15}
                            maxLength={128}
                            placeholder="At least 15 characters"
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">Confirm password</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            required
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            disabled={processing}
                            placeholder="Confirm password"
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>

                    <div className="space-y-3 text-sm">
                        <label className="flex items-start gap-3">
                            <input
                                type="checkbox"
                                required
                                checked={data.terms_acceptance}
                                onChange={(e) => setData('terms_acceptance', e.target.checked)}
                                className="mt-1 size-4"
                            />
                            <span>
                                I accept the{' '}
                                <a href="/terms" target="_blank" rel="noreferrer" className="underline">
                                    development terms
                                </a>
                                .
                            </span>
                        </label>
                        <InputError message={errors.terms_acceptance} />
                        <label className="flex items-start gap-3">
                            <input
                                type="checkbox"
                                required
                                checked={data.privacy_acknowledgement}
                                onChange={(e) => setData('privacy_acknowledgement', e.target.checked)}
                                className="mt-1 size-4"
                            />
                            <span>
                                I acknowledge the{' '}
                                <a href="/privacy" target="_blank" rel="noreferrer" className="underline">
                                    draft privacy notice
                                </a>
                                .
                            </span>
                        </label>
                        <InputError message={errors.privacy_acknowledgement} />
                        <p className="text-muted-foreground text-xs">Development preview. Use fictitious information only.</p>
                    </div>
                    <Button type="submit" className="mt-2 w-full" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Create account
                    </Button>
                </div>

                <div className="text-muted-foreground text-center text-sm">
                    Already have an account? <TextLink href={route('login')}>Log in</TextLink>
                </div>
            </form>
        </AuthLayout>
    );
}
