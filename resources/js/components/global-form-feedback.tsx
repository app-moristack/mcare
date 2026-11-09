import { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const fieldName = (key: string) => {
    const leaf =
        key
            .split('.')
            .filter((part) => !/^\d+$/.test(part))
            .at(-1) ?? key;
    return leaf.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
};

export default function GlobalFormFeedback() {
    const { errors, flash } = usePage<SharedData>().props;
    const validationMessages = useMemo(
        () => Array.from(new Set(Object.entries(errors ?? {}).map(([key, message]) => `${fieldName(key)}: ${String(message)}`))),
        [errors],
    );
    const messages = validationMessages.length ? validationMessages : flash?.error ? [flash.error] : [];
    const errorKey = messages.join('|');
    const successKey = flash?.success ?? '';
    const [dismissedErrors, setDismissedErrors] = useState('');
    const [dismissedSuccess, setDismissedSuccess] = useState('');
    const alert = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (errorKey && errorKey !== dismissedErrors) alert.current?.focus();
    }, [errorKey, dismissedErrors]);

    const showErrors = !!errorKey && dismissedErrors !== errorKey;
    const showSuccess = !showErrors && !!successKey && dismissedSuccess !== successKey;
    if (!showErrors && !showSuccess) return null;

    return (
        <div className="pointer-events-none fixed inset-x-0 top-3 z-[100] mx-auto w-[calc(100%-1.5rem)] max-w-xl sm:top-5" aria-live="assertive">
            {showErrors ? (
                <div
                    ref={alert}
                    tabIndex={-1}
                    role="alert"
                    className="pointer-events-auto max-h-[70svh] overflow-y-auto rounded-2xl border border-red-300 bg-red-50 p-4 text-red-950 shadow-2xl outline-none dark:border-red-800 dark:bg-red-950 dark:text-red-100"
                >
                    <div className="flex items-start gap-3">
                        <AlertCircle aria-hidden="true" className="mt-0.5 size-5 shrink-0" />
                        <div className="min-w-0 flex-1">
                            <p className="font-semibold">Your changes were not saved</p>
                            <p className="mt-1 text-sm">Correct the following {messages.length === 1 ? 'field' : 'fields'} and try again.</p>
                            <ul className="mt-3 list-disc space-y-1 pl-5 text-sm">
                                {messages.map((message) => (
                                    <li className="break-words" key={message}>
                                        {message}
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <button
                            type="button"
                            className="min-h-11 min-w-11 rounded-lg p-2"
                            aria-label="Dismiss validation messages"
                            onClick={() => setDismissedErrors(errorKey)}
                        >
                            <X aria-hidden="true" className="size-5" />
                        </button>
                    </div>
                </div>
            ) : (
                <div
                    role="status"
                    className="pointer-events-auto rounded-2xl border border-green-300 bg-green-50 p-4 text-green-950 shadow-2xl dark:border-green-800 dark:bg-green-950 dark:text-green-100"
                >
                    <div className="flex items-start gap-3">
                        <CheckCircle2 aria-hidden="true" className="mt-0.5 size-5 shrink-0" />
                        <p className="min-w-0 flex-1 text-sm font-medium break-words">{successKey}</p>
                        <button
                            type="button"
                            className="min-h-11 min-w-11 rounded-lg p-2"
                            aria-label="Dismiss success message"
                            onClick={() => setDismissedSuccess(successKey)}
                        >
                            <X aria-hidden="true" className="size-5" />
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
