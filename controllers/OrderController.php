<?php

// ============================================================
//  Florería Los Lirios — OrderController
// ============================================================

class OrderController {

    private OrderModel   $orderModel;
    private ProductModel $productModel;

    // Llave secreta de Culqi — usar variable de entorno en producción
    private string $culqiSecretKey;

    public function __construct() {
        $this->orderModel     = new OrderModel();
        $this->productModel   = new ProductModel();
        $this->culqiSecretKey = getenv('CULQI_SECRET_KEY') ?: 'sk_test_xxxxxxxxxxxxxxxx';
    }

    // --------------------------------------------------------
    // Endpoints públicos
    // --------------------------------------------------------

    // POST /api/orders
    public function store(): never {
        $body = getRequestBody();

        // Validar campos obligatorios del cliente
        $required = [
            'cliente_nombre', 'cliente_apellido', 'cliente_telefono',
            'fecha_entrega', 'hora_entrega', 'tipo_entrega', 'metodo_pago',
            'items',
        ];

        $missing = missingField($body, $required);
        if ($missing) {
            jsonResponse(false, null, "El campo '{$missing}' es requerido.", 422);
        }

        // Validar que haya ítems
        if (!is_array($body['items']) || count($body['items']) === 0) {
            jsonResponse(false, null, 'El pedido debe contener al menos un producto.', 422);
        }

        // Validar teléfono peruano (9 dígitos empezando en 9)
        $telefono = preg_replace('/\D/', '', $body['cliente_telefono']);
        if (!preg_match('/^9\d{8}$/', $telefono)) {
            jsonResponse(false, null, 'El teléfono debe ser un número celular peruano válido (9 dígitos).', 422);
        }

        // Validar email si se proporcionó
        if (!empty($body['cliente_email']) && !filter_var($body['cliente_email'], FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, null, 'El email no tiene un formato válido.', 422);
        }

        // Validar fecha de entrega (mínimo mañana)
        $fechaEntrega = $body['fecha_entrega'];
        if (!$this->isValidFutureDate($fechaEntrega)) {
            jsonResponse(false, null, 'La fecha de entrega debe ser a partir de mañana.', 422);
        }

        // Validar tipo de entrega
        if (!in_array($body['tipo_entrega'], ['delivery', 'recojo'], true)) {
            jsonResponse(false, null, 'El tipo de entrega debe ser "delivery" o "recojo".', 422);
        }

        // Validar método de pago
        if (!in_array($body['metodo_pago'], ['culqi', 'yape', 'efectivo'], true)) {
            jsonResponse(false, null, 'Método de pago no válido.', 422);
        }

        // Si es delivery, requerir dirección y distrito
        if ($body['tipo_entrega'] === 'delivery') {
            if (empty($body['direccion']) || empty($body['distrito'])) {
                jsonResponse(false, null, 'La dirección y distrito son requeridos para delivery.', 422);
            }
        }

        // Validar y calcular ítems contra la base de datos
        [$items, $subtotal, $validationError] = $this->validateAndPriceItems($body['items']);

        if ($validationError) {
            jsonResponse(false, null, $validationError, 422);
        }

        // Calcular delivery
        $deliveryCost = ($body['tipo_entrega'] === 'delivery') ? 10.00 : 0.00;
        $total        = $subtotal + $deliveryCost;

        // Procesar pago con Culqi si corresponde
        $culqiToken = null;
        $estado     = 'pendiente';

        if ($body['metodo_pago'] === 'culqi') {
            if (empty($body['culqi_token'])) {
                jsonResponse(false, null, 'Se requiere el token de Culqi para pagar con tarjeta.', 422);
            }

            $chargeResult = $this->processCulqiCharge(
                token:   $body['culqi_token'],
                amount:  (int) round($total * 100), // Culqi trabaja en céntimos
                email:   $body['cliente_email'] ?? 'cliente@floreria.com',
                orderId: 'TEMP-' . time(),
            );

            if (!$chargeResult['success']) {
                jsonResponse(false, null, 'El pago con tarjeta fue rechazado: ' . $chargeResult['message'], 402);
            }

            $culqiToken = $chargeResult['charge_id'];
            $estado     = 'confirmado';
        }

        // Preparar datos del pedido
        $orderData = [
            'cliente_nombre'    => $body['cliente_nombre'],
            'cliente_apellido'  => $body['cliente_apellido'],
            'cliente_telefono'  => $telefono,
            'cliente_email'     => $body['cliente_email']    ?? '',
            'direccion'         => $body['direccion']        ?? '',
            'distrito'          => $body['distrito']         ?? '',
            'fecha_entrega'     => $fechaEntrega,
            'hora_entrega'      => $body['hora_entrega'],
            'tipo_entrega'      => $body['tipo_entrega'],
            'nota_dedicatoria'  => $body['nota_dedicatoria'] ?? '',
            'metodo_pago'       => $body['metodo_pago'],
            'estado'            => $estado,
            'subtotal'          => $subtotal,
            'delivery'          => $deliveryCost,
            'total'             => $total,
            'culqi_token'       => $culqiToken,
        ];

        try {
            $result = $this->orderModel->create($orderData, $items);

            // Decrementar stock de cada producto
            foreach ($items as $item) {
                $this->productModel->decrementStock((int) $item['product_id'], (int) $item['cantidad']);
            }

            jsonResponse(true, [
                'order_id' => $result['id'],
                'codigo'   => $result['codigo'],
                'total'    => $total,
                'estado'   => $estado,
            ], 'Pedido creado correctamente.', 201);

        } catch (\Throwable $e) {
            error_log('[OrderController::store] ' . $e->getMessage());
            jsonResponse(false, null, 'No se pudo guardar el pedido. Por favor intenta nuevamente.', 500);
        }
    }

    // GET /api/orders/{codigo}  (confirmación pública)
    public function showByCode(string $codigo): never {
        $order = $this->orderModel->getByCode($codigo);

        if (!$order) {
            jsonResponse(false, null, 'Pedido no encontrado.', 404);
        }

        jsonResponse(true, ['order' => $order], '');
    }

    // --------------------------------------------------------
    // Endpoints admin (requieren JWT)
    // --------------------------------------------------------

    // GET /api/admin/orders
    public function adminIndex(): never {
        AuthMiddleware::verify();

        $filters = [
            'estado'      => $_GET['estado']      ?? '',
            'fecha'       => $_GET['fecha']        ?? '',
            'metodo_pago' => $_GET['metodo_pago']  ?? '',
            'search'      => $_GET['search']       ?? '',
            'page'        => $_GET['page']          ?? 1,
            'per_page'    => $_GET['per_page']      ?? 20,
        ];

        $result = $this->orderModel->getAll($filters);
        jsonResponse(true, $result, '');
    }

    // GET /api/admin/orders/{id}
    public function adminShow(int $id): never {
        AuthMiddleware::verify();

        $order = $this->orderModel->getById($id);

        if (!$order) {
            jsonResponse(false, null, 'Pedido no encontrado.', 404);
        }

        jsonResponse(true, ['order' => $order], '');
    }

    // PUT /api/admin/orders/{id}
    public function adminUpdate(int $id): never {
        AuthMiddleware::verify();

        $order = $this->orderModel->getById($id);
        if (!$order) {
            jsonResponse(false, null, 'Pedido no encontrado.', 404);
        }

        $body = getRequestBody();

        $rows = $this->orderModel->update($id, $body);

        if ($rows === 0) {
            // No hubo cambios reales o no se enviaron campos válidos
            jsonResponse(true, ['order' => $order], 'Sin cambios detectados.');
        }

        $updated = $this->orderModel->getById($id);
        jsonResponse(true, ['order' => $updated], 'Pedido actualizado correctamente.');
    }

    // GET /api/admin/dashboard
    public function dashboard(): never {
        AuthMiddleware::verify();

        $stats           = $this->orderModel->getDashboardStats();
        $stats['productos_activos'] = $this->productModel->countActive();
        $stats['ultimos_pedidos']   = $this->orderModel->getLatest(10);

        jsonResponse(true, $stats, '');
    }

    // --------------------------------------------------------
    // Helpers privados
    // --------------------------------------------------------

    /**
     * Valida los ítems del carrito contra la base de datos.
     * Devuelve [items_validados, subtotal, error_string|null]
     */
    private function validateAndPriceItems(array $rawItems): array {
        $items    = [];
        $subtotal = 0.0;

        foreach ($rawItems as $rawItem) {
            if (empty($rawItem['product_id']) || empty($rawItem['cantidad'])) {
                return [[], 0, 'Ítem del carrito con datos incompletos.'];
            }

            $productId = (int) $rawItem['product_id'];
            $cantidad  = max(1, (int) $rawItem['cantidad']);

            $product = $this->productModel->getActiveById($productId);

            if (!$product) {
                return [[], 0, "El producto con ID {$productId} no está disponible."];
            }

            if ((int) $product['stock'] < $cantidad) {
                return [[], 0, "Stock insuficiente para \"{$product['nombre']}\" (disponible: {$product['stock']})."];
            }

            $itemSubtotal = (float) $product['precio'] * $cantidad;
            $subtotal    += $itemSubtotal;

            $items[] = [
                'product_id' => $productId,
                'nombre'     => $product['nombre'],
                'precio'     => (float) $product['precio'],
                'cantidad'   => $cantidad,
                'subtotal'   => $itemSubtotal,
            ];
        }

        return [$items, $subtotal, null];
    }

    /**
     * Valida que la fecha sea al menos mañana.
     */
    private function isValidFutureDate(string $fecha): bool {
        $parsed   = \DateTime::createFromFormat('Y-m-d', $fecha);
        $tomorrow = new \DateTime('tomorrow');
        $tomorrow->setTime(0, 0, 0);

        return $parsed !== false && $parsed >= $tomorrow;
    }

    /**
     * Realiza el cargo en Culqi.
     * Documentación: https://apidocs.culqi.com/#tag/Cargos/operation/crear-cargo
     */
    private function processCulqiCharge(string $token, int $amount, string $email, string $orderId): array {
        $payload = json_encode([
            'amount'        => $amount,
            'currency_code' => 'PEN',
            'email'         => $email,
            'source_id'     => $token,
            'description'   => 'Pedido Florería Los Lirios ' . $orderId,
            'metadata'      => ['order_id' => $orderId],
        ]);

        $ch = curl_init('https://api.culqi.com/v2/charges');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->culqiSecretKey,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log('[Culqi cURL Error] ' . $curlError);
            return ['success' => false, 'message' => 'Error de conexión con el procesador de pagos.'];
        }

        $data = json_decode($response, true);

        if ($httpCode === 201 && isset($data['id'])) {
            return [
                'success'   => true,
                'charge_id' => $data['id'],
            ];
        }

        // Culqi devuelve el error en data.user_message o data.merchant_message
        $errorMsg = $data['user_message']
            ?? $data['merchant_message']
            ?? 'Pago rechazado. Verifica los datos de tu tarjeta.';

        error_log('[Culqi Charge Error] HTTP ' . $httpCode . ' | ' . $response);

        return ['success' => false, 'message' => $errorMsg];
    }
}
