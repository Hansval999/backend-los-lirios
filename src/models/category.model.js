import { fetchAll, fetchOne, fetchScalar, insert, execute } from '../config/db.js';

export const CategoryModel = {
  // Tienda pública
  getAllActive() {
    return fetchAll(
      `SELECT id, nombre, descripcion, icono
         FROM categories
        WHERE activo = 1
        ORDER BY nombre ASC`
    );
  },

  // Panel admin (con conteo de productos)
  getAll() {
    return fetchAll(
      `SELECT c.id, c.nombre, c.descripcion, c.icono, c.activo, c.created_at,
              COUNT(p.id) AS total_productos
         FROM categories c
         LEFT JOIN products p ON p.category_id = c.id
        GROUP BY c.id
        ORDER BY c.nombre ASC`
    );
  },

  getById(id) {
    return fetchOne('SELECT * FROM categories WHERE id = ?', [id]);
  },

  create({ nombre, descripcion, icono, activo }) {
    return insert(
      'INSERT INTO categories (nombre, descripcion, icono, activo) VALUES (?, ?, ?, ?)',
      [nombre, descripcion, icono, activo]
    );
  },

  update(id, { nombre, descripcion, icono, activo }) {
    return execute(
      `UPDATE categories
          SET nombre = ?, descripcion = ?, icono = ?, activo = ?
        WHERE id = ?`,
      [nombre, descripcion, icono, activo, id]
    );
  },

  // Solo elimina si no tiene productos asociados
  async delete(id) {
    const count = Number(await fetchScalar('SELECT COUNT(*) FROM products WHERE category_id = ?', [id]));
    if (count > 0) {
      return { deleted: false, reason: `No se puede eliminar: tiene ${count} producto(s) asociado(s).` };
    }
    const rows = await execute('DELETE FROM categories WHERE id = ?', [id]);
    return { deleted: rows > 0, reason: '' };
  },

  async nameExists(nombre, excludeId = 0) {
    const count = Number(
      await fetchScalar('SELECT COUNT(*) FROM categories WHERE nombre = ? AND id != ?', [nombre, excludeId])
    );
    return count > 0;
  },
};
