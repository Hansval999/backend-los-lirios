import { ProductModel } from '../models/product.model.js';
import { CategoryModel } from '../models/category.model.js';
import { ok, fail } from '../utils/http.js';
import { str, toBool, missingField, isHttpUrl } from '../utils/helpers.js';

function parseBody(body = {}) {
  return {
    category_id: parseInt(body.category_id, 10),
    nombre: str(body.nombre),
    descripcion: str(body.descripcion),
    precio: Number(body.precio),
    stock: parseInt(body.stock ?? 0, 10) || 0,
    imagen_url: str(body.imagen_url),
    activo: toBool(body.activo, 1),
    featured: toBool(body.featured, 0),
  };
}

// Devuelve el primer mensaje de error o null
function validate(body) {
  const missing = missingField(body, ['nombre', 'category_id', 'precio']);
  if (missing) return `El campo '${missing}' es requerido.`;

  const d = parseBody(body);
  if (d.nombre.length < 2) return 'El nombre debe tener al menos 2 caracteres.';
  if (!(d.precio > 0)) return 'El precio debe ser mayor a 0.';
  if (d.stock < 0) return 'El stock no puede ser negativo.';
  if (d.imagen_url && !isHttpUrl(d.imagen_url)) return 'La URL de la imagen no tiene un formato válido.';
  return null;
}

export const ProductController = {
  // GET /api/products
  async index(req, res) {
    const { category_id, search, sort, precio_min, precio_max, page, per_page } = req.query;
    const result = await ProductModel.getActive({
      category_id,
      search,
      sort,
      precio_min,
      precio_max,
      page,
      per_page,
    });
    return ok(res, result);
  },

  // GET /api/products/featured
  async featured(req, res) {
    const limit = Math.min(20, Math.max(1, parseInt(req.query.limit, 10) || 8));
    return ok(res, { products: await ProductModel.getFeatured(limit) });
  },

  // GET /api/products/:id
  async show(req, res) {
    const product = await ProductModel.getActiveById(Number(req.params.id));
    if (!product) return fail(res, 404, 'Producto no encontrado.');
    return ok(res, { product });
  },

  // GET /api/admin/products
  async adminIndex(req, res) {
    const { search, category_id, activo, page, per_page } = req.query;
    return ok(res, await ProductModel.getAllAdmin({ search, category_id, activo, page, per_page }));
  },

  // POST /api/admin/products
  async store(req, res) {
    const error = validate(req.body);
    if (error) return fail(res, 422, error);

    const data = parseBody(req.body);
    if (!(await CategoryModel.getById(data.category_id))) {
      return fail(res, 422, 'La categoría seleccionada no existe.');
    }

    const id = await ProductModel.create(data);
    return ok(res, { product: await ProductModel.getById(id) }, 'Producto creado correctamente.', 201);
  },

  // PUT /api/admin/products/:id
  async update(req, res) {
    const id = Number(req.params.id);
    if (!(await ProductModel.getById(id))) return fail(res, 404, 'Producto no encontrado.');

    const error = validate(req.body);
    if (error) return fail(res, 422, error);

    const data = parseBody(req.body);
    if (!(await CategoryModel.getById(data.category_id))) {
      return fail(res, 422, 'La categoría seleccionada no existe.');
    }

    await ProductModel.update(id, data);
    return ok(res, { product: await ProductModel.getById(id) }, 'Producto actualizado correctamente.');
  },

  // DELETE /api/admin/products/:id
  async destroy(req, res) {
    const id = Number(req.params.id);
    if (!(await ProductModel.getById(id))) return fail(res, 404, 'Producto no encontrado.');

    await ProductModel.delete(id);
    return ok(res, null, 'Producto eliminado correctamente.');
  },
};
