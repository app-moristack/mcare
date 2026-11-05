import { Head } from '@inertiajs/react';

import AppearanceTabs from '@/components/appearance-tabs';
import ColorThemePicker from '@/components/color-theme-picker';
import HeadingSmall from '@/components/heading-small';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: '/settings/appearance',
    },
];

export default function Appearance() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Appearance settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Appearance settings" description="Update your account's appearance settings" />
                    <ColorThemePicker />
                    <HeadingSmall title="Display mode" description="Choose a lighter or darker version of your theme." />
                    <AppearanceTabs />
                    <p className="text-muted-foreground text-sm">
                        Your choice is saved on this browser and applies throughout the app, including sign-in pages.
                    </p>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
