export const isOn = (value) => value === '1' || value === true;

// The URL params of the current Votantes filters with the KPI card filters changed: null removes one.
export function withCardFilters(filters, changes) {
    const next = { ...filters, ...changes };
    Object.keys(next).forEach(k => { if (next[k] === null || next[k] === undefined || next[k] === '') delete next[k]; });
    return next;
}
