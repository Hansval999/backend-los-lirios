import { OrderModel } from '../models/order.model.js';
import { ProductModel } from '../models/product.model.js';
import { createCulqiCharge } from '../services/culqi.service.js';
import { env } from '../config/env.js';
import { ok, fail, HttpError } from '../utils/http.js';
import { str, missingField, isEmail, isValidFutureDate } from '../utils/helpers.js';

// Valida los ítems contra la BD y calcula precios (nunca confiamos en el precio del cliente)
async function validateAndPriceItems(rawItems) {
  const items = [];
  let subtotal = 0;

  for (const raw of rawItems) {
    if (!raw?.product_id || !raw?.cantidad) {
      return { error: 'Ítem del carrito con datos incompletos.' };
    }

    const productId = parseInt(raw.product_id, 10);
    const cantidad = Math.min(99, Math.max(1, parseInt(raw.cantidad, 10) || 1));

    const product = await ProductModel.getActiveById(productId);
    if (!product) return { error: `El producto con ID ${productId} no está disponible.` };

    if (Number(product.stock) < cantidad) {
      return { error: `Stock insuficiente para "${product.nombre}" (disponible: ${product.stock}).` };
    }

    const precio = Number(product.precio);
    const itemSubtotal = Math.round(precio * cantidad * 100) / 100;
    subtotal += itemSubtotal;

    items.push({ product_id: productId, nombre: product.nombre, precio, cantidad, subtotal: itemSubtotal });
  }

  return { items, subtotal: Math.round(subtotal * 100) / 100 };
}

export const OrderController = {
  // POST /api/orders
  async store(req, res) {
    const body = req.body ?? {};

    const missing = missingField(body, [
      'cliente_nombre',
      'cliente_apellido',
      'cliente_telefono',
      'fecha_entrega',
      'hora_entrega',
      'tipo_entrega',
      'metodo_pago',
      'items',
    ]);
    if (missing) return fail(res, 422, `El campo '${missing}' es requerido.`);

    if (!Array.isArray(body.items) || body.items.length === 0) {
      return fail(res, 422, 'El pedido debe contener al menos un producto.');
    }
    if (body.items.length > 50) {
      return fail(res, 422, 'El pedido tiene demasiados productos.');
    }

    // Teléfono peruano: 9 dígitos empezando en 9
    const telefono = String(body.cliente_telefono).replace(/\D/g, '');
    if (!/^9\d{8}$/.test(telefono)) {
      return fail(res, 422, 'El teléfono debe ser un número celular peruano válido (9 dígitos).');
    }

    const email = str(body.cliente_email);
    if (email && !isEmail(email)) {
      return fail(res, 422, 'El email no tiene un formato válido.');
    }

    const fechaEntrega = str(body.fecha_entrega);
    if (!isValidFutureDate(fechaEntrega)) {
      return fail(res, 422, 'La fecha de entrega debe ser a partir de mañana.');
    }

    const horaEntrega = str(body.hora_entrega);
    if (!/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/.test(horaEntrega)) {
      return fail(res, 422, 'La hora de entrega no es válida.');
    }

    if (!['delivery', 'recojo'].includes(body.tipo_entrega)) {
      return fail(res, 422, 'El tipo de entrega debe ser "delivery" o "recojo".');
    }
    if (!['culqi', 'yape', 'efectivo'].includes(body.metodo_pago)) {
      return fail(res, 422, 'Método de pago no válido.');
    }
    if (body.tipo_entrega === 'delivery' && (!str(body.direccion) || !str(body.distrito))) {
      return fail(res, 422, 'La dirección y distrito son requeridos para delivery.');
    }

    const { items, subtotal, error } = await validateAndPriceItems(body.items);
    if (error) return fail(res, 422, error);

    const deliveryCost = body.tipo_entrega === 'delivery' ? env.deliveryCost : 0;
    const total = Math.round((subtotal + deliveryCost) * 100) / 100;

    // Pago con tarjeta (Culqi)
    let culqiToken = null;
    let estado = 'pendiente';

    if (body.metodo_pago === 'culqi') {
      if (!body.culqi_token) {
        return fail(res, 422, 'Se requiere el token de Culqi para pagar con tarjeta.');
      }

      const charge = await createCulqiCharge({
        token: String(body.culqi_token),
        amount: Math.round(total * 100), // céntimos
        email: email || 'cliente@floreria.com',
        orderRef: `TEMP-${Date.now()}`,
      });

      if (!charge.success) {
        return fail(res, 402, `El pago con tarjeta fue rechazado: ${charge.message}`);
      }
      culqiToken = charge.chargeId;
      estado = 'confirmado';
    }

    try {
      const result = await OrderModel.create(
        {
          cliente_nombre: str(body.cliente_nombre),
          cliente_apellido: str(body.cliente_apellido),
          cliente_telefono: telefono,
          cliente_email: email,
          direccion: str(body.direccion),
          distrito: str(body.distrito),
          fecha_entrega: fechaEntrega,
          hora_entrega: horaEntrega,
          tipo_entrega: body.tipo_entrega,
          nota_dedicatoria: str(body.nota_dedicatoria),
          metodo_pago: body.metodo_pago,
          estado,
          subtotal,
          delivery: deliveryCost,
          total,
          culqi_token: culqiToken,
        },
        items
      );

      return ok(
        res,
        { order_id: result.id, codigo: result.codigo, total, estado },
        'Pedido creado correctamente.',
        201
      );
    } catch (err) {
      // Si ya se cobró con tarjeta y el pedido no se pudo guardar, hay que revisarlo a mano
      if (culqiToken) {
        console.error(`[ATENCIÓN] Cargo Culqi ${culqiToken} cobrado pero el pedido NO se guardó:`, err.message);
      }
      if (err instanceof HttpError) throw err;
      console.error('[OrderController.store]', err);
      return fail(res, 500, 'No se pudo guardar el pedido. Por favor intenta nuevamente.');
    }
  },

  // GET /api/orders/:codigo  (confirmación pública)
  async showByCode(req, res) {
    const order = await OrderModel.getByCode(String(req.params.codigo));
    if (!order) return fail(res, 404, 'Pedido no encontrado.');
    return ok(res, { order });
  },

  // GET /api/admin/orders
  async adminIndex(req, res) {
    const { estado, fecha, metodo_pago, search, page, per_page } = req.query;
    return ok(res, await OrderModel.getAll({ estado, fecha, metodo_pago, search, page, per_page }));
  },

  // GET /api/admin/orders/:id
  async adminShow(req, res) {
    const order = await OrderModel.getById(Number(req.params.id));
    if (!order) return fail(res, 404, 'Pedido no encontrado.');
    return ok(res, { order });
  },

  // PUT /api/admin/orders/:id
  async adminUpdate(req, res) {
    const id = Number(req.params.id);
    const order = await OrderModel.getById(id);
    if (!order) return fail(res, 404, 'Pedido no encontrado.');

    const rows = await OrderModel.update(id, req.body ?? {});
    if (rows === 0) return ok(res, { order }, 'Sin cambios detectados.');

    return ok(res, { order: await OrderModel.getById(id) }, 'Pedido actualizado correctamente.');
  },

  // GET /api/admin/dashboard
  async dashboard(req, res) {
    const stats = await OrderModel.getDashboardStats();
    stats.productos_activos = await ProductModel.countActive();
    stats.ultimos_pedidos = await OrderModel.getLatest(10);
    return ok(res, stats);
  },
};
