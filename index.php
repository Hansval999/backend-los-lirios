<?php

// ============================================================
//  Florería Los Lirios — Entry Point (Front Controller)
//  Toda petición a la API pasa por aquí gracias al .htaccess
// ============================================================

declare(strict_types=1);

// ------------------------------------------------------------
// 1. CORS — debe ir antes que cualquier output
// ------------------------------------------------------------

require_once __DIR__ . '/config/cors.php';

// ------------------------------------------------------------
// 2. Autoload manual de clases
//    (sin Composer en InfinityFree tier gratuito)
// ------------------------------------------------------------

$classMap = [
    // Config
    'Database'             => __DIR__ . '/config/database.php',

    // Middleware
    'AuthMiddleware'       => __DIR__ . '/middleware/auth.php',

    // Models
    'ProductModel'         => __DIR__ . '/models/Product.php',
    'OrderModel'           => __DIR__ . '/models/Order.php',
    'CategoryModel'        => __DIR__ . '/models/Category.php',
    'UserModel'            => __DIR__ . '/models/User.php',

    // Controllers
    'ProductController'    => __DIR__ . '/controllers/ProductController.php',
    'OrderController'      => __DIR__ . '/controllers/OrderController.php',
    'CategoryController'   => __DIR__ . '/controllers/CategoryController.php',
    'AuthController'       => __DIR__ . '/controllers/AuthController.php',
];

spl_autoload_register(function (string $class) use ($classMap): void {
    if (isset($classMap[$class])) {
        require_once $classMap[$class];
    }
});

// ------------------------------------------------------------
// 3. Helpers globales
// ------------------------------------------------------------

/**
 * Envía una respuesta JSON estándar y termina el script.
 *
 * @param bool   $success  Indica si la operación fue exitosa
 * @param mixed  $data     Payload de la respuesta
 * @param string $message  Mensaje legible para el cliente
 * @param int    $code     Código HTTP
 */
function jsonResponse(bool $success, mixed $data, string $message = '', int $code = 200): never {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data'    => $data,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Obtiene y decodifica el body JSON de la petición entrante.
 * Devuelve array vacío si el body no es JSON válido.
 */
function getRequestBody(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Sanitiza un string de entrada.
 */
function sanitize(string $value): string {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

/**
 * Verifica que los campos requeridos estén presentes en un array.
 * Devuelve el nombre del primer campo faltante o null si todo está OK.
 */
function missingField(array $data, array $required): ?string {
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            return $field;
        }
    }
    return null;
}

// ------------------------------------------------------------
// 4. Parsear la URL y el método HTTP
// ------------------------------------------------------------

$requestUri    = $_SERVER['REQUEST_URI']    ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Quitar el query string de la URI para el routing
$uriPath = parse_url($requestUri, PHP_URL_PATH);

// Normalizar: quitar barra final y asegurar que empiece con /
$uriPath = '/' . trim($uriPath, '/');

// Si el backend está en un subdirectorio (ej: /api), ajustar aquí:
// $basePath = '/api';
// $uriPath  = substr($uriPath, strlen($basePath)) ?: '/';

// ------------------------------------------------------------
// 5. Enrutamiento — delegar al router principal
// ------------------------------------------------------------

require_once __DIR__ . '/routes/api.php';

// ------------------------------------------------------------
// 6. Ruta no encontrada (fallback)
// ------------------------------------------------------------

jsonResponse(false, null, 'Endpoint no encontrado.', 404);
