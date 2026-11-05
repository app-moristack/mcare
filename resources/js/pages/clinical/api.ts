export class ClinicalRequestError extends Error {
    constructor(public errors: Record<string, string>) {
        super(Object.values(errors)[0] ?? 'Request failed.');
    }
}
export async function saveClinical(url: string, payload: unknown) {
    const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    const response = await fetch(url, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '',
        },
        body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const errors = data.errors
            ? Object.fromEntries(Object.entries(data.errors as Record<string, string[]>).map(([k, v]) => [k, v[0]]))
            : {
                  request:
                      response.status === 409
                          ? 'This record changed or was locked. Reload before continuing.'
                          : response.status === 419
                            ? 'Your session changed. Reload before saving.'
                            : response.status === 403
                              ? 'Your clinical access is no longer current.'
                              : (data.message ?? 'Unable to save. Check your connection and try again.'),
              };
        throw new ClinicalRequestError(errors);
    }
    return data as { id: number; version: number; status: string };
}
