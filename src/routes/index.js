import { Router } from 'express';
import rateLimit from 'express-rate-limit';
import { requireAuth } from '../middleware/auth.js';
import { asyncHandler as h } from '../utils/http.js';
import { AuthController } from '../controllers/auth.controller.js';
import { CategoryController } from '../controllers/category.controller.js';
import { ProductController } from '../controllers/product.controller.js';
import { OrderController } from '../controllers/order.controller.js';

const router = Router();

// Anti fuerza bruta en el login: 10 intentos / 15 min por IP
const loginLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  limit: 10,
  standardHeaders: true,
  legacyHeaders: false,
  message: { success: false, data: null, message: 'Demasiados intentos. Intenta de nuevo en unos minutos.' },
});

// Límite para crear pedidos: 30 / 15 min por IP
const orderLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  limit: 30,
  standardHeaders: true,
  legacyHeaders: false,
  message: { success: false, data: null, message: 'Demasiadas solicitudes. Intenta de nuevo en unos minutos.' },
});

// ---------- Auth ----------
router.post('/auth/login', loginLimiter, h(AuthController.login));
router.get('/auth/me', requireAuth, h(AuthController.me));

// ---------- Públicas ----------
router.get('/categories', h(CategoryController.index));

router.get('/products', h(ProductController.index));
router.get('/products/featured', h(ProductController.featured)); // antes de /:id
router.get('/products/:id(\\d+)', h(ProductController.show));

router.post('/orders', orderLimiter, h(OrderController.store));
router.get('/orders/:codigo', h(OrderController.showByCode));

// ---------- Admin (JWT) ----------
const admin = Router();
admin.use(requireAuth);

admin.get('/dashboard', h(OrderController.dashboard));

admin.get('/products', h(ProductController.adminIndex));
admin.post('/products', h(ProductController.store));
admin.put('/products/:id(\\d+)', h(ProductController.update));
admin.delete('/products/:id(\\d+)', h(ProductController.destroy));

admin.get('/orders', h(OrderController.adminIndex));
admin.get('/orders/:id(\\d+)', h(OrderController.adminShow));
admin.put('/orders/:id(\\d+)', h(OrderController.adminUpdate));

admin.get('/categories', h(CategoryController.adminIndex));
admin.post('/categories', h(CategoryController.store));
admin.put('/categories/:id(\\d+)', h(CategoryController.update));
admin.delete('/categories/:id(\\d+)', h(CategoryController.destroy));

router.use('/admin', admin);

export default router;
