<?php

// ============================================================
//  Florería Los Lirios — ProductController
// ============================================================

class ProductController {

    private ProductModel   $productModel;
    private CategoryModel  $categoryModel;

    public function __construct() {
        $this->productModel  = new ProductModel();
        $this->categoryModel = new CategoryModel();
    }

    // --------------------------------------------------------
    // Endpoints públicos
    // --------------------------------------------------------

    // GET /api/products
    public function index(): never {
        $filters = [
            'category_id' => $_GET['category_id'] ?? '',
            'search'      => $_GET['search']       ?? '',
            'sort'        => $_GET['sort']          ?? '',
            'precio_min'  => $_GET['precio_min']   ?? '',
            'precio_max'  => $_GET['precio_max']   ?? '',
            'page'        => $_GET['page']          ?? 1,
            'per_page'    => $_GET['per_page']      ?? 12,
        ];

        $result = $this->productModel->getActive($filters);
        jsonResponse(true, $result, '');
    }

    // GET /api/products/featured
    public function featured(): never {
        $limit    = min(20, max(1, (int)($_GET['limit'] ?? 8)));
        $products = $this->productModel->getFeatured($limit);
        jsonResponse(true, ['products' => $products], '');
    }

    // GET /api/products/{id}
    public function show(int $id): never {
        $product = $this->productModel->getActiveById($id);

        if (!$product) {
            jsonResponse(false, null, 'Producto no encontrado.', 404);
        }

        jsonResponse(true, ['product' => $product], '');
    }

    // --------------------------------------------------------
    // Endpoints admin (requieren JWT)
    // --------------------------------------------------------

    // GET /api/admin/products
    public function adminIndex(): never {
        AuthMiddleware::verify();

        $filters = [
            'search'      => $_GET['search']      ?? '',
            'category_id' => $_GET['category_id'] ?? '',
            'activo'      => $_GET['activo']       ?? '',
            'page'        => $_GET['page']         ?? 1,
            'per_page'    => $_GET['per_page']     ?? 20,
        ];

        $result = $this->productModel->getAllAdmin($filters);
        jsonResponse(true, $result, '');
    }

    // POST /api/admin/products
    public function store(): never {
        AuthMiddleware::verify();

        $body = getRequestBody();

        $missing = missingField($body, ['nombre', 'category_id', 'precio']);
        if ($missing) {
            jsonResponse(false, null, "El campo '{$missing}' es requerido.", 422);
        }

        if (!$this->validateProductData($body, $errors)) {
            jsonResponse(false, null, $errors[0], 422);
        }

        // Verificar que la categoría exista
        if (!$this->categoryModel->getById((int) $body['category_id'])) {
            jsonResponse(false, null, 'La categoría seleccionada no existe.', 422);
        }

        $id      = $this->productModel->create($body);
        $product = $this->productModel->getById((int) $id);

        jsonResponse(true, ['product' => $product], 'Producto creado correctamente.', 201);
    }

    // PUT /api/admin/products/{id}
    public function update(int $id): never {
        AuthMiddleware::verify();

        $product = $this->productModel->getById($id);
        if (!$product) {
            jsonResponse(false, null, 'Producto no encontrado.', 404);
        }

        $body = getRequestBody();

        $missing = missingField($body, ['nombre', 'category_id', 'precio']);
        if ($missing) {
            jsonResponse(false, null, "El campo '{$missing}' es requerido.", 422);
        }

        if (!$this->validateProductData($body, $errors)) {
            jsonResponse(false, null, $errors[0], 422);
        }

        if (!$this->categoryModel->getById((int) $body['category_id'])) {
            jsonResponse(false, null, 'La categoría seleccionada no existe.', 422);
        }

        $this->productModel->update($id, $body);
        $updated = $this->productModel->getById($id);

        jsonResponse(true, ['product' => $updated], 'Producto actualizado correctamente.');
    }

    // DELETE /api/admin/products/{id}
    public function destroy(int $id): never {
        AuthMiddleware::verify();

        $product = $this->productModel->getById($id);
        if (!$product) {
            jsonResponse(false, null, 'Producto no encontrado.', 404);
        }

        $this->productModel->delete($id);
        jsonResponse(true, null, 'Producto eliminado correctamente.');
    }

    // --------------------------------------------------------
    // Validaciones internas
    // --------------------------------------------------------

    private function validateProductData(array $data, array &$errors): bool {
        $errors = [];

        if (mb_strlen(trim($data['nombre'] ?? '')) < 2) {
            $errors[] = 'El nombre debe tener al menos 2 caracteres.';
        }

        $precio = (float)($data['precio'] ?? 0);
        if ($precio <= 0) {
            $errors[] = 'El precio debe ser mayor a 0.';
        }

        $stock = (int)($data['stock'] ?? 0);
        if ($stock < 0) {
            $errors[] = 'El stock no puede ser negativo.';
        }

        if (!empty($data['imagen_url']) && !filter_var($data['imagen_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'La URL de la imagen no tiene un formato válido.';
        }

        return empty($errors);
    }
}
