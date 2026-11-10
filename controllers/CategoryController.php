<?php

// ============================================================
//  Florería Los Lirios — CategoryController
// ============================================================

class CategoryController {

    private CategoryModel $categoryModel;

    public function __construct() {
        $this->categoryModel = new CategoryModel();
    }

    // --------------------------------------------------------
    // Endpoints públicos
    // --------------------------------------------------------

    // GET /api/categories
    public function index(): never {
        $categories = $this->categoryModel->getAllActive();
        jsonResponse(true, ['categories' => $categories], '');
    }

    // --------------------------------------------------------
    // Endpoints admin (requieren JWT)
    // --------------------------------------------------------

    // GET /api/admin/categories
    public function adminIndex(): never {
        AuthMiddleware::verify();

        $categories = $this->categoryModel->getAll();
        jsonResponse(true, ['categories' => $categories], '');
    }

    // POST /api/admin/categories
    public function store(): never {
        AuthMiddleware::verify();

        $body = getRequestBody();

        $missing = missingField($body, ['nombre']);
        if ($missing) {
            jsonResponse(false, null, "El campo '{$missing}' es requerido.", 422);
        }

        if (mb_strlen(trim($body['nombre'])) < 2) {
            jsonResponse(false, null, 'El nombre debe tener al menos 2 caracteres.', 422);
        }

        if ($this->categoryModel->nameExists($body['nombre'])) {
            jsonResponse(false, null, 'Ya existe una categoría con ese nombre.', 409);
        }

        $id = $this->categoryModel->create($body);
        $category = $this->categoryModel->getById((int) $id);

        jsonResponse(true, ['category' => $category], 'Categoría creada correctamente.', 201);
    }

    // PUT /api/admin/categories/{id}
    public function update(int $id): never {
        AuthMiddleware::verify();

        $category = $this->categoryModel->getById($id);
        if (!$category) {
            jsonResponse(false, null, 'Categoría no encontrada.', 404);
        }

        $body = getRequestBody();

        $missing = missingField($body, ['nombre']);
        if ($missing) {
            jsonResponse(false, null, "El campo '{$missing}' es requerido.", 422);
        }

        if (mb_strlen(trim($body['nombre'])) < 2) {
            jsonResponse(false, null, 'El nombre debe tener al menos 2 caracteres.', 422);
        }

        if ($this->categoryModel->nameExists($body['nombre'], $id)) {
            jsonResponse(false, null, 'Ya existe otra categoría con ese nombre.', 409);
        }

        $this->categoryModel->update($id, $body);
        $updated = $this->categoryModel->getById($id);

        jsonResponse(true, ['category' => $updated], 'Categoría actualizada correctamente.');
    }

    // DELETE /api/admin/categories/{id}
    public function destroy(int $id): never {
        AuthMiddleware::verify();

        $category = $this->categoryModel->getById($id);
        if (!$category) {
            jsonResponse(false, null, 'Categoría no encontrada.', 404);
        }

        $result = $this->categoryModel->delete($id);

        if (!$result['deleted']) {
            jsonResponse(false, null, $result['reason'], 409);
        }

        jsonResponse(true, null, 'Categoría eliminada correctamente.');
    }
}
