import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
interface Profile {
    legal_name: string | null;
    date_of_birth: string | null;
    mobile_phone: string | null;
    locality: string | null;
}
export default function Onboarding({ profile }: { profile: Profile }) {
    const { data, setData, patch, errors, processing } = useForm({
        legal_name: profile.legal_name ?? '',
        date_of_birth: profile.date_of_birth ?? '',
        mobile_phone: profile.mobile_phone ?? '',
        locality: profile.locality ?? '',
    });
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Overview', href: '/dashboard' },
                { title: 'Your details', href: '/onboarding' },
            ]}
        >
            <Head title="Your basic details" />
            <main className="mx-auto w-full max-w-2xl p-6 md:p-10">
                <p className="text-xs tracking-widest text-blue-700 uppercase">Getting started</p>
                <h1 className="mt-3 text-3xl font-semibold">Let’s start with you.</h1>
                <p className="text-muted-foreground mt-3 leading-relaxed">
                    Add your basic details. This does not verify your identity or replace a clinical assessment.
                </p>
                <form
                    className="surface mt-7 space-y-6 p-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        patch('/onboarding');
                    }}
                >
                    {(
                        [
                            ['legal_name', 'Legal name', 'text'],
                            ['date_of_birth', 'Date of birth', 'date'],
                            ['mobile_phone', 'Mobile phone (optional, e.g. +23051234567)', 'tel'],
                            ['locality', 'Town / locality', 'text'],
                        ] as const
                    ).map(([key, label, type]) => (
                        <div key={key} className="grid gap-2">
                            <Label htmlFor={key}>{label}</Label>
                            <Input
                                id={key}
                                type={type}
                                value={data[key]}
                                onChange={(e) => setData(key, e.target.value)}
                                required={key !== 'mobile_phone'}
                                disabled={processing}
                            />
                            <InputError message={errors[key]} />
                        </div>
                    ))}
                    <p className="text-muted-foreground text-sm">Use fictitious information during this development preview.</p>
                    <Button disabled={processing}>{processing ? 'Saving…' : 'Save your details'}</Button>
                </form>
            </main>
        </AppLayout>
    );
}
