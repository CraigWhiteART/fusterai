export function csrfHeaders(json = false): HeadersInit {
    const headers: Record<string, string> = {
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('[name="csrf-token"]')?.content ?? '',
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    };

    if (json) {
        headers['Content-Type'] = 'application/json';
    }

    return headers;
}
