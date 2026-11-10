// Equivalentes a las funciones auxiliares del index.php

export const str = (value, fallback = '') =>
  value === undefined || value === null ? fallback : String(value).trim();

// Devuelve el nombre del primer campo faltante o null (como missingField())
export function missingField(data, required) {
  for (const field of required) {
    const v = data?.[field];
    if (v === undefined || v === null || v === '') return field;
  }
  return null;
}

export const toBool = (value, fallback) =>
  value === undefined || value === null ? fallback : value === true || value === 1 || value === '1' || value === 'true' ? 1 : 0;

export const isEmail = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);

export function isHttpUrl(v) {
  try {
    const u = new URL(v);
    return u.protocol === 'http:' || u.protocol === 'https:';
  } catch {
    return false;
  }
}

// Paginación segura: page >= 1, perPage entre 1 y max
export function paginate(query, defaultPerPage, maxPerPage = 100) {
  const page = Math.max(1, parseInt(query.page, 10) || 1);
  const perPage = Math.min(maxPerPage, Math.max(1, parseInt(query.per_page, 10) || defaultPerPage));
  return { page, perPage, offset: (page - 1) * perPage };
}

// Escapa % y _ para búsquedas LIKE
export const likeTerm = (s) => `%${String(s).replace(/[\\%_]/g, '\\$&')}%`;

// Fecha/hora en Lima (UTC-5, sin horario de verano)
const limaParts = (d = new Date()) =>
  new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/Lima',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  })
    .format(d)
    .split('-');

export const limaToday = () => limaParts().join('-'); // YYYY-MM-DD
export const limaYear = () => limaParts()[0];
export const limaMonth = () => limaParts().slice(0, 2).join('-'); // YYYY-MM

export function isValidFutureDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const [y, m, d] = value.split('-').map(Number);
  const parsed = new Date(Date.UTC(y, m - 1, d));
  if (parsed.getUTCFullYear() !== y || parsed.getUTCMonth() !== m - 1 || parsed.getUTCDate() !== d) {
    return false; // fecha inexistente (p. ej. 2026-02-31)
  }
  const [ty, tm, td] = limaToday().split('-').map(Number);
  const tomorrow = new Date(Date.UTC(ty, tm - 1, td + 1));
  return parsed >= tomorrow;
}
