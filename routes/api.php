<?php

// ============================================================
//  Florería Los Lirios — Router Principal
//  Mapea METHOD + URI → Controller::method()
//  Variables disponibles desde index.php:
//    $uriPath       → string  (ej: "/api/products/5")
//    $requestMethod → string  (ej: "GET")
// ============================================================

// ------------------------------------------------------------
// Función auxiliar: extrae segmentos de la URI como parámetros
// Ej: matchRoute('/api/products/{id}', '/api/products/5')
//     → ['id' => '5']  |  false si no hace match
// ------------------------------------------------------------

function matchRoute(string $pattern, string $uri): array|false {
    // Convertir {param} → grupo de captura nombrado
    $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
    $regex = '#^' . $regex . '$#';

    if (!preg_match($regex, $uri, $matches)) {
        return false;
    }

    // Filtrar solo los grupos nombrados (quitar índices numéricos)
    return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
}

// ------------------------------------------------------------
// Definición de rutas
// Formato: [METHOD, pattern, Controller, method, ?params]
// ------------------------------------------------------------

$routes = [

    // ----------------------------------------------------------
    // Auth
    // ----------------------------------------------------------
    ['POST', '/api/auth/login',  'AuthController', 'login'],
    ['GET',  '/api/auth/me',     'AuthController', 'me'],

    // ----------------------------------------------------------
    // Públicas — Categorías
    // ----------------------------------------------------------
    ['GET', '/api/categories',   'CategoryController', 'index'],

    // ----------------------------------------------------------
    // Públicas — Productos
    // ----------------------------------------------------------
    ['GET', '/api/products',             'ProductController', 'index'],
    ['GET', '/api/products/featured',    'ProductController', 'featured'],
    ['GET', '/api/products/{id}',        'ProductController', 'show'],

    // ----------------------------------------------------------
    // Públicas — Pedidos
    // ----------------------------------------------------------
    ['POST', '/api/orders',              'OrderController', 'store'],
    ['GET',  '/api/orders/{codigo}',     'OrderController', 'showByCode'],

    // ----------------------------------------------------------
    // Admin — Dashboard
    // ----------------------------------------------------------
    ['GET', '/api/admin/dashboard',      'OrderController', 'dashboard'],

    // ----------------------------------------------------------
    // Admin — Productos
    // ----------------------------------------------------------
    ['GET',    '/api/admin/products',        'ProductController', 'adminIndex'],
    ['POST',   '/api/admin/products',        'ProductController', 'store'],
    ['PUT',    '/api/admin/products/{id}',   'ProductController', 'update'],
    ['DELETE', '/api/admin/products/{id}',   'ProductController', 'destroy'],

    // ----------------------------------------------------------
    // Admin — Pedidos
    // ----------------------------------------------------------
    ['GET', '/api/admin/orders',             'OrderController', 'adminIndex'],
    ['GET', '/api/admin/orders/{id}',        'OrderController', 'adminShow'],
    ['PUT', '/api/admin/orders/{id}',        'OrderController', 'adminUpdate'],

    // ----------------------------------------------------------
    // Admin — Categorías
    // ----------------------------------------------------------
    ['GET',    '/api/admin/categories',          'CategoryController', 'adminIndex'],
    ['POST',   '/api/admin/categories',          'CategoryController', 'store'],
    ['PUT',    '/api/admin/categories/{id}',     'CategoryController', 'update'],
    ['DELETE', '/api/admin/categories/{id}',     'CategoryController', 'destroy'],
];

// ------------------------------------------------------------
// Resolver la ruta actual
// ------------------------------------------------------------

$matched        = false;
$methodNotAllow = false;

foreach ($routes as [$method, $pattern, $controllerName, $action]) {

    $params = matchRoute($pattern, $uriPath);

    if ($params === false) {
        continue; // El patrón no coincide con la URI → siguiente ruta
    }

    // El patrón coincide pero el método HTTP no
    if ($method !== $requestMethod) {
        $methodNotAllow = true;
        continue;
    }

    // ✅ Match completo: instanciar controller y llamar el método

    $matched = true;

    $controller = new $controllerName();

    // Convertir parámetros al tipo correcto
    $resolvedParams = [];
    foreach ($params as $key => $value) {
        // Parámetros numéricos como {id} → int
        // Parámetros de texto como {codigo} → string
        $resolvedParams[] = is_numeric($value) ? (int) $value : (string) $value;
    }

    // Llamar al método del controller con los parámetros extraídos de la URL
    $controller->$action(...$resolvedParams);

    break; // Solo ejecutar la primera ruta que haga match completo
}

// ------------------------------------------------------------
// Respuestas de error si no hubo match
// ------------------------------------------------------------

if (!$matched) {
    if ($methodNotAllow) {
        jsonResponse(false, null, 'Método HTTP no permitido para este endpoint.', 405);
    }

    // El 404 definitivo lo maneja el fallback al final de index.php
    // (Este punto solo se alcanza si ninguna ruta hizo match de URI)
}
