import { env } from '../config/env.js';
import { HttpError, fail } from '../utils/http.js';

export function notFound(req, res) {
  return fail(res, 404, 'Endpoint no encontrado.');
}

// eslint-disable-next-line no-unused-vars
export function errorHandler(err, req, res, next) {
  if (err instanceof HttpError) {
    return fail(res, err.status, err.message);
  }

  // JSON mal formado en el body
  if (err.type === 'entity.parse.failed') {
    return fail(res, 400, 'El cuerpo de la petición no es un JSON válido.');
  }

  console.error('[Error]', err);

  const body = { success: false, data: null, message: 'Error interno del servidor.' };
  if (!env.isProd) body.debug = { error: err.message };
  return res.status(500).json(body);
}
