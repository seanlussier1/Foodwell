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
        // send() answers in the format negotiated from Accept (JSON, XML or
        // YAML, #65). In XML the list is <categories> with one <category> per item.
        Response::send(200, [
            'data' => $this->categories->findAll(),
        ], [], 'categories', 'category');
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

        Response::send(200, $category, [], 'category');
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

        // mb_strlen counts characters. strlen would count bytes, and letters
        // like "é" take 2 bytes, so a valid 60-letter name would be refused.
        // The database column is VARCHAR(100), which also counts characters (#41).
        if ($name === '' || mb_strlen($name) > 100) {
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

        Response::send(201, $category, [], 'category');
    }

    /** DELETE /api/v1/categories/{id} -> 200 | 404 | 409 */
    public function destroy(array $params): void
    {
        $id = (int) $params['id'];

        try {
            $category = $this->categories->delete($id);
        } catch (PDOException $e) {
            // 1451 = "row is still referenced". The repository checks for
            // products first, but if one is added in the split second between
            // that check and the DELETE, the database's foreign key refuses
            // the delete. Same answer as the normal "in use" case: 409, not 500 (#41).
            if (($e->errorInfo[1] ?? null) === 1451) {
                Response::error(409, 'Cannot delete a category that still has products.');
                return;
            }
            throw $e;
        }

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

        Response::send(200, [
            'message' => 'Category deleted successfully.',
            'data' => $category,
        ], [], 'categoryDeletion');
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