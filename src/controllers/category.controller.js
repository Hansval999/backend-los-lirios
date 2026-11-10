import { CategoryModel } from '../models/category.model.js';
import { ok, fail } from '../utils/http.js';
import { str, toBool, missingField } from '../utils/helpers.js';

function parseBody(body = {}) {
  return {
    nombre: str(body.nombre),
    descripcion: str(body.descripcion),
    icono: str(body.icono),
    activo: toBool(body.activo, 1),
  };
}

function validate(body) {
  const missing = missingField(body, ['nombre']);
  if (missing) return `El campo '${missing}' es requerido.`;
  if (str(body.nombre).length < 2) return 'El nombre debe tener al menos 2 caracteres.';
  return null;
}

export const CategoryController = {
  // GET /api/categories
  async index(req, res) {
    return ok(res, { categories: await CategoryModel.getAllActive() });
  },

  // GET /api/admin/categories
  async adminIndex(req, res) {
    return ok(res, { categories: await CategoryModel.getAll() });
  },

  // POST /api/admin/categories
  async store(req, res) {
    const error = validate(req.body);
    if (error) return fail(res, 422, error);

    const data = parseBody(req.body);
    if (await CategoryModel.nameExists(data.nombre)) {
      return fail(res, 409, 'Ya existe una categoría con ese nombre.');
    }

    const id = await CategoryModel.create(data);
    return ok(res, { category: await CategoryModel.getById(id) }, 'Categoría creada correctamente.', 201);
  },

  // PUT /api/admin/categories/:id
  async update(req, res) {
    const id = Number(req.params.id);
    if (!(await CategoryModel.getById(id))) return fail(res, 404, 'Categoría no encontrada.');

    const error = validate(req.body);
    if (error) return fail(res, 422, error);

    const data = parseBody(req.body);
    if (await CategoryModel.nameExists(data.nombre, id)) {
      return fail(res, 409, 'Ya existe otra categoría con ese nombre.');
    }

    await CategoryModel.update(id, data);
    return ok(res, { category: await CategoryModel.getById(id) }, 'Categoría actualizada correctamente.');
  },

  // DELETE /api/admin/categories/:id
  async destroy(req, res) {
    const id = Number(req.params.id);
    if (!(await CategoryModel.getById(id))) return fail(res, 404, 'Categoría no encontrada.');

    const result = await CategoryModel.delete(id);
    if (!result.deleted) return fail(res, 409, result.reason);

    return ok(res, null, 'Categoría eliminada correctamente.');
  },
};
