import { UserModel } from '../models/user.model.js';
import { generateToken } from '../middleware/auth.js';
import { ok, fail } from '../utils/http.js';
import { str, isEmail } from '../utils/helpers.js';

export const AuthController = {
  // POST /api/auth/login
  async login(req, res) {
    const email = str(req.body?.email).toLowerCase();
    const password = str(req.body?.password);

    if (!email || !password) {
      return fail(res, 422, 'Email y contraseña son requeridos.');
    }
    if (!isEmail(email)) {
      return fail(res, 422, 'El email no tiene un formato válido.');
    }

    const user = await UserModel.authenticate(email, password);
    if (!user) {
      // Mensaje genérico para no revelar si el email existe
      return fail(res, 401, 'Credenciales incorrectas.');
    }

    const token = generateToken({ user_id: user.id, email: user.email, rol: user.rol });

    return ok(
      res,
      { token, user: { id: user.id, nombre: user.nombre, email: user.email, rol: user.rol } },
      'Sesión iniciada correctamente.'
    );
  },

  // GET /api/auth/me
  async me(req, res) {
    const user = await UserModel.getById(Number(req.user.user_id));
    if (!user) return fail(res, 404, 'Usuario no encontrado.');
    return ok(res, { user });
  },
};
