import jwt from 'jsonwebtoken';
import { env } from '../config/env.js';
import { fail } from '../utils/http.js';

export function generateToken(payload) {
  return jwt.sign(payload, env.jwtSecret, { algorithm: 'HS256', expiresIn: env.jwtExpiresIn });
}

// Equivalente a AuthMiddleware::verify() — se usa como middleware de rutas admin
export function requireAuth(req, res, next) {
  const header = req.headers.authorization || '';
  if (!header.startsWith('Bearer ')) {
    return fail(res, 401, 'Token de autenticación no proporcionado.');
  }

  try {
    const payload = jwt.verify(header.slice(7).trim(), env.jwtSecret, { algorithms: ['HS256'] });
    req.user = payload;
    return next();
  } catch (err) {
    if (err.name === 'TokenExpiredError') {
      return fail(res, 401, 'La sesión ha expirado. Por favor inicia sesión nuevamente.');
    }
    return fail(res, 401, 'Token inválido o malformado.');
  }
}
