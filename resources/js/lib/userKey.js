// Opaque per-user key for browser storage, so the account UUID is never written to it.
// Without a secure context (plain HTTP outside localhost) WebCrypto is missing and the raw id is used.
export async function userKey(userId) {
    if (!userId) return null;
    if (!globalThis.crypto?.subtle) return `id:${userId}`;
    const digest = await globalThis.crypto.subtle.digest('SHA-256', new TextEncoder().encode(`electoral:${userId}`));
    return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
}
