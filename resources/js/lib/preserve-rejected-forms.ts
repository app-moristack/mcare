import { router } from '@inertiajs/react';

export function preserveRejectedForms() {
    router.on('before', (event) => {
        const visit = event.detail.visit;
        if (visit.method === 'get') return;
        if (visit.preserveState === false) visit.preserveState = 'errors';
        if (visit.preserveScroll === false) visit.preserveScroll = 'errors';
    });
}
