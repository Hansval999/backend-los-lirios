import { pool, fetchAll, fetchOne, fetchScalar, execute } from '../config/db.js';
import { HttpError } from '../utils/http.js';
import { likeTerm, paginate, limaToday, limaYear, limaMonth } from '../utils/helpers.js';

const VALID_STATES = ['pendiente', 'confirmado', 'preparando', 'enviado', 'entregado', 'cancelado'];
const EDITABLE_FIELDS = ['estado', 'nota_dedicatoria', 'fecha_entrega', 'hora_entrega', 'direccion', 'distrito'];

async function getItems(orderId) {
  return fetchAll(
    `SELECT oi.id, oi.product_id, oi.nombre_producto,
            oi.precio_unitario, oi.cantidad, oi.subtotal,
            p.imagen_url
       FROM order_items oi
       LEFT JOIN products p ON p.id = oi.product_id
      WHERE oi.order_id = ?
      ORDER BY oi.id ASC`,
    [orderId]
  );
}

// Código único: LIR-YYYY-XXXX. Se calcula dentro de la transacción
// con el último correlativo del año (bloqueado) para evitar duplicados.
async function generateCode(conn) {
  const year = limaYear();
  const prefix = `LIR-${year}-`;
  const last = await fetchOne(
    'SELECT codigo FROM orders WHERE codigo LIKE ? ORDER BY codigo DESC LIMIT 1 FOR UPDATE',
    [`${prefix}%`],
    conn
  );
  const lastNumber = last ? parseInt(last.codigo.slice(prefix.length), 10) || 0 : 0;
  return `${prefix}${String(lastNumber + 1).padStart(4, '0')}`;
}

// Errores transitorios de concurrencia: se reintenta la transacción completa
const RETRYABLE = new Set(['ER_LOCK_DEADLOCK', 'ER_DUP_ENTRY', 'ER_LOCK_WAIT_TIMEOUT']);

export const OrderModel = {
  // Crea pedido + ítems + descuenta stock en UNA transacción (con reintentos)
  async create(orderData, items) {
    const MAX_ATTEMPTS = 4;
    for (let attempt = 1; ; attempt++) {
      try {
        return await this.createOnce(orderData, items);
      } catch (err) {
        if (!RETRYABLE.has(err.code) || attempt >= MAX_ATTEMPTS) throw err;
        await new Promise((r) => setTimeout(r, 40 * attempt + Math.random() * 60));
      }
    }
  },

  async createOnce(orderData, items) {
    const conn = await pool.getConnection();
    try {
      await conn.beginTransaction();

      const codigo = await generateCode(conn);

      const [orderResult] = await conn.query(
        `INSERT INTO orders (
            codigo, cliente_nombre, cliente_apellido, cliente_telefono,
            cliente_email, direccion, distrito, fecha_entrega, hora_entrega,
            tipo_entrega, nota_dedicatoria, metodo_pago, estado,
            subtotal, delivery, total, culqi_token
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [
          codigo,
          orderData.cliente_nombre,
          orderData.cliente_apellido,
          orderData.cliente_telefono,
          orderData.cliente_email,
          orderData.direccion,
          orderData.distrito,
          orderData.fecha_entrega,
          orderData.hora_entrega,
          orderData.tipo_entrega,
          orderData.nota_dedicatoria,
          orderData.metodo_pago,
          orderData.estado,
          orderData.subtotal,
          orderData.delivery,
          orderData.total,
          orderData.culqi_token,
        ]
      );
      const orderId = orderResult.insertId;

      for (const item of items) {
        await conn.query(
          `INSERT INTO order_items
             (order_id, product_id, nombre_producto, precio_unitario, cantidad, subtotal)
           VALUES (?, ?, ?, ?, ?, ?)`,
          [orderId, item.product_id, item.nombre, item.precio, item.cantidad, item.subtotal]
        );

        // Descuento atómico: solo si todavía hay stock suficiente
        const [upd] = await conn.query(
          'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?',
          [item.cantidad, item.product_id, item.cantidad]
        );
        if (upd.affectedRows === 0) {
          throw new HttpError(409, `Stock insuficiente para "${item.nombre}". Intenta nuevamente.`);
        }
      }

      await conn.commit();
      return { id: orderId, codigo };
    } catch (err) {
      await conn.rollback();
      throw err;
    } finally {
      conn.release();
    }
  },

  async getByCode(codigo) {
    const order = await fetchOne('SELECT * FROM orders WHERE codigo = ?', [codigo]);
    if (!order) return null;
    order.items = await getItems(order.id);
    return order;
  },

  async getById(id) {
    const order = await fetchOne('SELECT * FROM orders WHERE id = ?', [id]);
    if (!order) return null;
    order.items = await getItems(id);
    return order;
  },

  async getAll(filters = {}) {
    const where = ['1=1'];
    const params = [];

    if (filters.estado) {
      where.push('o.estado = ?');
      params.push(filters.estado);
    }
    if (filters.fecha) {
      where.push('DATE(o.created_at) = ?');
      params.push(filters.fecha);
    }
    if (filters.metodo_pago) {
      where.push('o.metodo_pago = ?');
      params.push(filters.metodo_pago);
    }
    if (filters.search) {
      where.push(
        '(o.codigo LIKE ? OR o.cliente_nombre LIKE ? OR o.cliente_apellido LIKE ? OR o.cliente_telefono LIKE ?)'
      );
      const term = likeTerm(filters.search);
      params.push(term, term, term, term);
    }

    const { page, perPage, offset } = paginate(filters, 20, 100);
    const whereClause = `WHERE ${where.join(' AND ')}`;

    const total = Number(await fetchScalar(`SELECT COUNT(*) FROM orders o ${whereClause}`, params));

    const orders = await fetchAll(
      `SELECT o.id, o.codigo,
              o.cliente_nombre, o.cliente_apellido, o.cliente_telefono, o.cliente_email,
              o.tipo_entrega, o.metodo_pago, o.estado,
              o.subtotal, o.delivery, o.total,
              o.fecha_entrega, o.hora_entrega,
              o.created_at,
              COUNT(oi.id) AS total_items
         FROM orders o
         LEFT JOIN order_items oi ON oi.order_id = o.id
       ${whereClause}
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?`,
      [...params, perPage, offset]
    );

    return { orders, total, page, per_page: perPage, total_pages: Math.ceil(total / perPage) };
  },

  // Edita solo campos permitidos. Devuelve filas afectadas.
  async update(id, data) {
    const fields = [];
    const params = [];

    for (const field of EDITABLE_FIELDS) {
      if (data[field] === undefined || data[field] === null) continue;

      if (field === 'estado' && !VALID_STATES.includes(data.estado)) {
        throw new HttpError(422, 'Estado de pedido no válido.');
      }
      fields.push(`${field} = ?`);
      params.push(typeof data[field] === 'string' ? data[field].trim() : data[field]);
    }

    if (fields.length === 0) return 0;

    params.push(id);
    return execute(`UPDATE orders SET ${fields.join(', ')} WHERE id = ?`, params);
  },

  async getDashboardStats() {
    const [pedidosHoy, pedidosPendientes, ingresosMes] = await Promise.all([
      fetchScalar('SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ?', [limaToday()]),
      fetchScalar("SELECT COUNT(*) FROM orders WHERE estado = 'pendiente'"),
      fetchScalar(
        `SELECT COALESCE(SUM(total), 0)
           FROM orders
          WHERE DATE_FORMAT(created_at, '%Y-%m') = ?
            AND estado NOT IN ('cancelado')`,
        [limaMonth()]
      ),
    ]);

    return {
      pedidos_hoy: Number(pedidosHoy),
      pedidos_pendientes: Number(pedidosPendientes),
      ingresos_mes: Number(ingresosMes),
    };
  },

  getLatest(limit = 10) {
    return fetchAll(
      `SELECT o.id, o.codigo,
              CONCAT(o.cliente_nombre, ' ', o.cliente_apellido) AS cliente,
              o.total, o.metodo_pago, o.estado, o.created_at
         FROM orders o
        ORDER BY o.created_at DESC
        LIMIT ?`,
      [limit]
    );
  },
};
