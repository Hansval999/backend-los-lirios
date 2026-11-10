import express from 'express';
import helmet from 'helmet';
import cors from 'cors';
import { env } from './config/env.js';
import routes from './routes/index.js';
import { notFound, errorHandler } from './middleware/error.js';

const app = express();

// Render (y la mayoría de PaaS) están detrás de un proxy: necesario para rate-limit por IP real
app.set('trust proxy', 1);
app.disable('x-powered-by');

app.use(helmet());

app.use(
  cors({
    origin(origin, callback) {
      // Sin Origin = curl/Postman/health checks del servidor
      if (!origin || env.corsOrigins.includes(origin)) return callback(null, true);
      return callback(null, false);
    },
    methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization', 'Accept'],
    maxAge: 86400,
  })
);

app.use(express.json({ limit: '100kb' }));

// Health check (útil para Render)
app.get('/health', (req, res) => res.json({ success: true, data: { status: 'ok' }, message: '' }));

app.use('/api', routes);

app.use(notFound);
app.use(errorHandler);

export default app;
