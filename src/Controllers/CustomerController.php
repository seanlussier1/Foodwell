<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Http\Response;
use Foodwell\Repositories\CustomerRepository;
use Foodwell\Validation\CustomerRegistration;
use Foodwell\Validation\CustomerCollection;
use Foodwell\Env;
use JsonException;
use PDOException;

final class CustomerController
{
    public function __construct(private CustomerRepository $customers)
    {
    }

    public function register(array $params = []): void
    {
        $input = $this->readJson();
        if ($input === null) { return; }
        $result = CustomerRegistration::validate($input);
        if ($result['errors'] !== []) {
            Response::error(422, 'Validation failed.', [], $result['errors']);
            return;
        }
        try {
            $customer = $this->customers->register($result['data']);
        } catch (PDOException $e) {
            // The unique DB index also protects simultaneous registrations.
            if (($e->errorInfo[1] ?? null) === 1062) {
                Response::error(409, 'An account with this email already exists.');
                return;
            }
            throw $e;
        }
        Response::json(201, $customer);
    }

    public function show(array $params): void
    {
        if (!$this->allowLocalDemo()) { return; }
        $id = $this->profileId($params);
        if ($id === null) { return; }
        $customer = $this->customers->findById($id);
        if ($customer === null) {
            Response::error(404, 'Customer not found.');
            return;
        }
        Response::json(200, $customer);
    }

    public function index(array $params = []): void
    {
        if (!$this->allowLocalDemo()) { return; }
        $validated = CustomerCollection::validate($_GET);
        if ($validated['errors'] !== []) {
            Response::error(400, 'Invalid query parameters.', [], $validated['errors']);
            return;
        }
        $filters = $validated['data'];
        $result = $this->customers->findPage($filters);
        $totalPages = (int) ceil($result['total'] / $filters['limit']);
        // Relative links preserve Apache subfolders without trusting the Host header.
        $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $query = ['limit' => $filters['limit']];
        if (array_key_exists('search', $_GET)) { $query['search'] = $filters['search']; }
        if ($filters['isActive'] !== null) { $query['isActive'] = $filters['isActive'] ? 'true' : 'false'; }
        $link = static fn(int $page): string => $path . '?' . http_build_query(array_merge(['page' => $page], $query), '', '&', PHP_QUERY_RFC3986);
        Response::json(200, [
            'metadata' => [
                'page' => $filters['page'], 'limit' => $filters['limit'],
                'total' => $result['total'], 'totalPages' => $totalPages,
                'next' => $filters['page'] < $totalPages ? $link($filters['page'] + 1) : null,
                'previous' => $totalPages > 0 && $filters['page'] > 1 ? $link(min($filters['page'] - 1, $totalPages)) : null,
            ],
            'data' => $result['data'],
        ]);
    }

    public function update(array $params): void
    {
        if (!$this->allowLocalDemo()) { return; }
        $id = $this->profileId($params);
        if ($id === null) { return; }
        $input = $this->readJson();
        if ($input === null) { return; }
        $result = CustomerRegistration::validate($input, false);
        if ($result['errors'] !== []) {
            Response::error(422, 'Validation failed.', [], $result['errors']);
            return;
        }
        try {
            $customer = $this->customers->updateProfile($id, $result['data']);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                Response::error(409, 'An account with this email already exists.');
                return;
            }
            throw $e;
        }
        if ($customer === null) {
            Response::error(404, 'Customer not found.');
            return;
        }
        Response::json(200, $customer);
    }

    /** Until #45/#46, profile access is a deliberately local-only demonstration. */
    private function allowLocalDemo(): bool
    {
        if (Env::get('APP_CUSTOMER_PROFILE_DEMO', '0') !== '1'
            || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            Response::error(403, 'Customer profile demo is disabled. Authentication and permissions are not implemented yet.');
            return false;
        }
        return true;
    }

    private function profileId(array $params): ?int
    {
        $id = filter_var($params['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        if ($id === false) {
            Response::error(404, 'Customer not found.');
            return null;
        }
        return $id;
    }

    private function readJson(): ?array
    {
        $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($type !== 'application/json') {
            Response::error(415, 'This operation requires Content-Type: application/json.');
            return null;
        }
        try {
            $input = json_decode((string) file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
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
