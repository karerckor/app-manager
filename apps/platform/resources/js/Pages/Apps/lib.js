export const formatSize = (bytes) => {
    if (bytes == null) return '';
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
    return `${value.toFixed(value < 10 && unit > 0 ? 1 : 0)} ${units[unit]}`;
};

export const initials = (name) => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();

export const formatBytes = (bytes) => bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
export const formatDate = (value) => value ? new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(value)) : 'No expiration';
export const formatDateTime = (value) => value ? new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
export const formatParameters = (value) => JSON.stringify(value ?? {}, null, 2);
export const formatMetadata = (value) => value == null ? '' : JSON.stringify(value, null, 2);
export const parseMetadata = (value, setError) => {
    if (!value.trim()) {
        setError('');

        return null;
    }

    try {
        const parsed = JSON.parse(value);

        if (parsed === null || Array.isArray(parsed) || typeof parsed !== 'object') {
            setError('Metadata must be a JSON object.');

            return undefined;
        }

        setError('');

        return parsed;
    } catch (error) {
        setError(`Invalid JSON: ${error.message}`);

        return undefined;
    }
};
export const buildStatusClass = (status) => ({ completed: 'bg-emerald-50 text-emerald-700', running: 'bg-sky-50 text-sky-700', pending: 'bg-indigo-50 text-indigo-700', cancel_requested: 'bg-amber-50 text-amber-700', failed: 'bg-rose-50 text-rose-700', cancelled: 'bg-slate-100 text-slate-600' }[status] ?? 'bg-slate-100 text-slate-600');
export const firstFormError = (form, prefix) => Object.entries(form.errors ?? {}).find(([key]) => key === prefix || key.startsWith(`${prefix}.`))?.[1];
export const parseParameters = (value, setError) => {
    try {
        const parsed = JSON.parse(value);

        if (parsed === null || Array.isArray(parsed) || typeof parsed !== 'object') {
            setError('Parameters must be a JSON object.');

            return null;
        }

        setError('');

        return parsed;
    } catch (error) {
        setError(`Invalid JSON: ${error.message}`);

        return null;
    }
};

export const isExpiring = (value) => value && new Date(value).getTime() < Date.now() + 1000 * 60 * 60 * 24 * 30;
