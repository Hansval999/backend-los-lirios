// Respuesta JSON estándar — mismo formato que jsonResponse() de PHP:
// { success, data, message }

export class HttpError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

export function ok(res, data = null, message = '', status = 200) {
  return res.status(status).json({ success: true, data, message });
}

export function fail(res, status, message, data = null) {
  return res.status(status).json({ success: false, data, message });
}

// Envuelve handlers async para que los errores lleguen al middleware de errores
export const asyncHandler = (fn) => (req, res, next) =>
  Promise.resolve(fn(req, res, next)).catch(next);
