import 'dotenv/config';

const isProd = process.env.NODE_ENV === 'production';

function required(name) {
  const value = process.env[name];
  if (!value) {
    throw new Error(`Falta la variable de entorno obligatoria: ${name}`);
  }
  return value;
}

// En producción exigimos secretos reales; en desarrollo hay valores por defecto.
const jwtSecret = isProd ? required('JWT_SECRET') : process.env.JWT_SECRET || 'dev-secret-solo-local';
if (isProd && jwtSecret.length < 32) {
  throw new Error('JWT_SECRET debe tener al menos 32 caracteres en producción.');
}

export const env = {
  isProd,
  port: Number(process.env.PORT) || 8000,

  db: {
    host: isProd ? required('DB_HOST') : process.env.DB_HOST || 'localhost',
    port: Number(process.env.DB_PORT) || 3306,
    name: isProd ? required('DB_NAME') : process.env.DB_NAME || 'floreria_los_lirios',
    user: isProd ? required('DB_USER') : process.env.DB_USER || 'root',
    pass: process.env.DB_PASS ?? '',
    ssl: process.env.DB_SSL === 'true',
  },

  jwtSecret,
  jwtExpiresIn: process.env.JWT_EXPIRES_IN || '8h',

  corsOrigins: (process.env.CORS_ORIGIN || 'http://localhost:5173')
    .split(',')
    .map((o) => o.trim())
    .filter(Boolean),

  culqiSecretKey: process.env.CULQI_SECRET_KEY || '',
  deliveryCost: Number(process.env.DELIVERY_COST ?? 10),
};
