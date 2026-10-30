<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Http\Response;
use Foodwell\Repositories\CategoryRepository;
use PDOException;
use JsonException;

final class CategoryController
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    /** GET /api/v1/categories -> 200 {"data": [...]} */
    public function index(array $params = []): void
    {
        Response::json(200, [
            'data' => $this->categories->findAll(),
        ]);
    }

    /** GET /api/v1/categories/{id} -> 200 Category | 404 error */
    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $category = $this->categories->findById($id);

        if ($category === null) {
            Response::error(404, 'Category not found.');
            return;
        }

        Response::json(200, $category);
    }

    /** POST /api/v1/categories -> 201 Category | 409 duplicate name */
    public function store(array $params = []): void
    {
        $input = $this->readJson();

        if ($input === null) {
            return;
        }

        $name = $input['name'] ?? null;

        if (!is_string($name)) {
            Response::error(
                422,
                'Validation failed.',
                [],
                ['name' => 'The name field is required and must be a string.']
            );
            return;
        }

        $name = trim($name);

        if ($name === '' || strlen($name) > 100) {
            Response::error(
                422,
                'Validation failed.',
                [],
                ['name' => 'The name must be between 1 and 100 characters.']
            );
            return;
        }

        try {
            $category = $this->categories->create($name);
        } catch (PDOException $e) {
            // The unique database constraint also protects simultaneous requests.
            if (($e->errorInfo[1] ?? null) === 1062) {
                Response::error(409, 'A category with this name already exists.');
                return;
            }

            throw $e;
        }

        Response::json(201, $category);
    }

    /** DELETE /api/v1/categories/{id} -> 200 | 404 | 409 */
    public function destroy(array $params): void
    {
        $id = (int) $params['id'];

        $category = $this->categories->delete($id);

        if ($category === null) {
            Response::error(404, 'Category not found.');
            return;
        }

        if (isset($category['inUse']) && $category['inUse'] === true) {
            Response::error(
                409,
                'Cannot delete a category that still has products.'
            );
            return;
        }

        Response::json(200, [
            'message' => 'Category deleted successfully.',
            'data' => $category,
        ]);
    }

    /** Read and validate the JSON request body. */
    private function readJson(): ?array
    {
        $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));

        if ($type !== 'application/json') {
            Response::error(
                415,
                'This operation requires Content-Type: application/json.'
            );
            return null;
        }

        try {
            $input = json_decode(
                (string) file_get_contents('php://input'),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            Response::error(400, 'Malformed JSON body.');
            return null;
        }

        if (!$input instanceof \stdClass) {
            Response::error(400, 'The request body must be a JSON object.');
            return null;
        }

        return (array) $input;
    }
}