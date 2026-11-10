import bcrypt from 'bcryptjs';
import { fetchOne, execute, insert } from '../config/db.js';

export const UserModel = {
  getByEmail(email) {
    return fetchOne(
      'SELECT id, nombre, email, password, rol FROM users WHERE email = ? LIMIT 1',
      [email]
    );
  },

  getById(id) {
    return fetchOne('SELECT id, nombre, email, rol, created_at FROM users WHERE id = ?', [id]);
  },

  // Verifica credenciales y devuelve el usuario sin el hash (o null)
  async authenticate(email, password) {
    const user = await this.getByEmail(email);
    if (!user) return null;

    // bcryptjs entiende los hashes $2y$ generados por password_hash() de PHP
    const valid = await bcrypt.compare(password, user.password);
    if (!valid) return null;

    const { password: _hash, ...safeUser } = user;
    return safeUser;
  },

  async create(nombre, email, password) {
    const hash = await bcrypt.hash(password, 10);
    return insert('INSERT INTO users (nombre, email, password, rol) VALUES (?, ?, ?, ?)', [
      nombre,
      email,
      hash,
      'admin',
    ]);
  },

  async updatePassword(id, newPassword) {
    const hash = await bcrypt.hash(newPassword, 10);
    return execute('UPDATE users SET password = ? WHERE id = ?', [hash, id]);
  },
};
