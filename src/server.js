import app from './app.js';
import { env } from './config/env.js';
import { pool } from './config/db.js';

const server = app.listen(env.port, () => {
  console.log(`API Florería Los Lirios escuchando en el puerto ${env.port} (${env.isProd ? 'producción' : 'desarrollo'})`);
});

// Cierre ordenado (Render envía SIGTERM al redeployar)
async function shutdown(signal) {
  console.log(`${signal} recibido, cerrando...`);
  server.close(async () => {
    await pool.end().catch(() => {});
    process.exit(0);
  });
  setTimeout(() => process.exit(1), 10_000).unref();
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
