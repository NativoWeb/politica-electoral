export function getCsrfToken() {
    const cookie = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='));
    if (cookie) return decodeURIComponent(cookie.split('=')[1]);
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

export function apiFetch(url, options = {}) {
    return fetch(url, {
        ...options,
        cache: 'no-store',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
            ...options.headers,
        },
        credentials: 'same-origin',
    });
}

export function showGlobalToast(text, type = 'success') {
    const existing = document.getElementById('global-offline-toast');
    if (existing) existing.remove();
    const toast = document.createElement('div');
    toast.id = 'global-offline-toast';
    const bg = type === 'success' ? '#059669' : type === 'error' ? '#ef4444' : '#2563eb';
    toast.style.cssText = `position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:9999;padding:16px 28px;background:${bg};color:white;border-radius:16px;font-size:16px;font-weight:700;box-shadow:0 8px 32px rgba(0,0,0,0.3);text-align:center;max-width:90vw;animation:slideDown 0.3s ease-out;`;
    toast.textContent = text;
    const style = document.createElement('style');
    style.textContent = '@keyframes slideDown{from{opacity:0;transform:translateX(-50%) translateY(-20px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}';
    toast.appendChild(style);
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.3s'; setTimeout(() => toast.remove(), 300); }, 4000);
}
