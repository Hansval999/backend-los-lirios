import mysql from 'mysql2/promise';
import { env } from './env.js';

export const pool = mysql.createPool({
  host: env.db.host,
  port: env.db.port,
  database: env.db.name,
  user: env.db.user,
  password: env.db.pass,
  charset: 'utf8mb4',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
  // DATE/DATETIME como string ('2026-11-08'), igual que hacía PDO en PHP
  dateStrings: true,
  ssl: env.db.ssl ? { rejectUnauthorized: true } : undefined,
});

// Cada conexión trabaja en hora de Lima (UTC-5), como el schema original
pool.on('connection', (conn) => {
  conn.query("SET time_zone = '-05:00'");
});

// Helpers cortos. Usamos query() (placeholders ? escapados por el driver).
export async function fetchAll(sql, params = [], conn = pool) {
  const [rows] = await conn.query(sql, params);
  return rows;
}

export async function fetchOne(sql, params = [], conn = pool) {
  const rows = await fetchAll(sql, params, conn);
  return rows[0] ?? null;
}

export async function fetchScalar(sql, params = [], conn = pool) {
  const row = await fetchOne(sql, params, conn);
  return row ? Object.values(row)[0] : null;
}

export async function insert(sql, params = [], conn = pool) {
  const [result] = await conn.query(sql, params);
  return result.insertId;
}

export async function execute(sql, params = [], conn = pool) {
  const [result] = await conn.query(sql, params);
  return result.affectedRows;
}
