import pool from './db.js';

async function testConnection() {
  try {
    const res = await pool.query('SELECT NOW()');
    console.log('¡Conexión exitosa a Supabase!');
    console.log('Fecha y hora actual en la base de datos:', res.rows[0].now);
    process.exit(0);
  } catch (err) {
    console.error('Error al conectar con la base de datos:', err);
    process.exit(1);
  }
}

testConnection();