import { fetchAll, fetchOne, fetchScalar, insert, execute } from '../config/db.js';
import { likeTerm, paginate } from '../utils/helpers.js';

const PUBLIC_COLUMNS = `p.id, p.nombre, p.descripcion, p.precio, p.stock,
        p.imagen_url, p.featured,
        c.id AS category_id, c.nombre AS category_nombre, c.icono AS category_icono`;

const SORTS = {
  precio_asc: 'p.precio ASC',
  precio_desc: 'p.precio DESC',
  nombre_az: 'p.nombre ASC',
};

export const ProductModel = {
  // ---------- Tienda pública ----------

  async getActive(filters = {}) {
    const where = ['p.activo = 1'];
    const params = [];

    if (filters.category_id) {
      where.push('p.category_id = ?');
      params.push(parseInt(filters.category_id, 10) || 0);
    }
    if (filters.search) {
      where.push('(p.nombre LIKE ? OR p.descripcion LIKE ?)');
      const term = likeTerm(filters.search);
      params.push(term, term);
    }
    if (filters.precio_min) {
      where.push('p.precio >= ?');
      params.push(Number(filters.precio_min) || 0);
    }
    if (filters.precio_max) {
      where.push('p.precio <= ?');
      params.push(Number(filters.precio_max) || 0);
    }

    const orderBy = SORTS[filters.sort] ?? 'p.featured DESC, p.created_at DESC';
    const { page, perPage, offset } = paginate(filters, 12, 50);
    const whereClause = `WHERE ${where.join(' AND ')}`;

    const total = Number(await fetchScalar(`SELECT COUNT(*) FROM products p ${whereClause}`, params));

    const products = await fetchAll(
      `SELECT ${PUBLIC_COLUMNS}
         FROM products p
         INNER JOIN categories c ON c.id = p.category_id
       ${whereClause}
        ORDER BY ${orderBy}
        LIMIT ? OFFSET ?`,
      [...params, perPage, offset]
    );

    return { products, total, page, per_page: perPage, total_pages: Math.ceil(total / perPage) };
  },

  getFeatured(limit = 8) {
    return fetchAll(
      `SELECT ${PUBLIC_COLUMNS}
         FROM products p
         INNER JOIN categories c ON c.id = p.category_id
        WHERE p.activo = 1 AND p.featured = 1
        ORDER BY p.created_at DESC
        LIMIT ?`,
      [limit]
    );
  },

  getActiveById(id, conn) {
    return fetchOne(
      `SELECT p.id, p.nombre, p.descripcion, p.precio, p.stock,
              p.imagen_url, p.activo, p.featured, p.created_at,
              c.id AS category_id, c.nombre AS category_nombre, c.icono AS category_icono
         FROM products p
         INNER JOIN categories c ON c.id = p.category_id
        WHERE p.id = ? AND p.activo = 1`,
      [id],
      conn
    );
  },

  // ---------- Admin ----------

  async getAllAdmin(filters = {}) {
    const where = ['1=1'];
    const params = [];

    if (filters.search) {
      where.push('(p.nombre LIKE ? OR p.descripcion LIKE ?)');
      const term = likeTerm(filters.search);
      params.push(term, term);
    }
    if (filters.category_id !== undefined && filters.category_id !== '') {
      where.push('p.category_id = ?');
      params.push(parseInt(filters.category_id, 10) || 0);
    }
    if (filters.activo !== undefined && filters.activo !== '') {
      where.push('p.activo = ?');
      params.push(parseInt(filters.activo, 10) ? 1 : 0);
    }

    const { page, perPage, offset } = paginate(filters, 20, 100);
    const whereClause = `WHERE ${where.join(' AND ')}`;

    const total = Number(await fetchScalar(`SELECT COUNT(*) FROM products p ${whereClause}`, params));

    const products = await fetchAll(
      `SELECT p.id, p.nombre, p.descripcion, p.precio, p.stock,
              p.imagen_url, p.activo, p.featured, p.created_at,
              c.id AS category_id, c.nombre AS category_nombre
         FROM products p
         INNER JOIN categories c ON c.id = p.category_id
       ${whereClause}
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?`,
      [...params, perPage, offset]
    );

    return { products, total, page, per_page: perPage, total_pages: Math.ceil(total / perPage) };
  },

  getById(id) {
    return fetchOne(
      `SELECT p.*, c.nombre AS category_nombre
         FROM products p
         INNER JOIN categories c ON c.id = p.category_id
        WHERE p.id = ?`,
      [id]
    );
  },

  create(d) {
    return insert(
      `INSERT INTO products
         (category_id, nombre, descripcion, precio, stock, imagen_url, activo, featured)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
      [d.category_id, d.nombre, d.descripcion, d.precio, d.stock, d.imagen_url, d.activo, d.featured]
    );
  },

  update(id, d) {
    return execute(
      `UPDATE products
          SET category_id = ?, nombre = ?, descripcion = ?, precio = ?,
              stock = ?, imagen_url = ?, activo = ?, featured = ?
        WHERE id = ?`,
      [d.category_id, d.nombre, d.descripcion, d.precio, d.stock, d.imagen_url, d.activo, d.featured, id]
    );
  },

  delete(id) {
    return execute('DELETE FROM products WHERE id = ?', [id]);
  },

  async countActive() {
    return Number(await fetchScalar('SELECT COUNT(*) FROM products WHERE activo = 1'));
  },
};
