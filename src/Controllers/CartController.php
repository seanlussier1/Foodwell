<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Env;
use Foodwell\Http\Response;
use Foodwell\Repositories\CartException;
use Foodwell\Repositories\CartRepository;
use JsonException;

final class CartController
{
    public function __construct(private CartRepository $carts) {}

    public function show(array $params): void { $this->handle('get', $params); }
    public function add(array $params): void { $this->handle('add', $params); }
    public function update(array $params): void { $this->handle('update', $params); }
    public function remove(array $params): void { $this->handle('remove', $params); }

    private function handle(string $operation, array $params): void
    {
        if (Env::get('APP_CART_DEMO', '0') !== '1' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            Response::error(403, 'Cart demo is disabled. Authentication and ownership enforcement remain planned.');
            return;
        }
        foreach ($params as $value) {
            if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) === false) {
                Response::error(404, 'Customer or cart item not found.');
                return;
            }
        }
        $input = [];
        if ($operation === 'add' || $operation === 'update') {
            $input = $this->readInput($operation === 'add');
            if ($input === null) { return; }
        }
        try {
            $customerId = (int) $params['id'];
            if ($operation === 'get') { Response::json(200, $this->carts->get($customerId)); }
            elseif ($operation === 'add') {
                $result = $this->carts->add($customerId, $input['productId'], $input['quantity']);
                Response::json($result['created'] ? 201 : 200, $result['cart']);
            } elseif ($operation === 'update') {
                Response::json(200, $this->carts->update($customerId, (int) $params['itemId'], $input['quantity']));
            } else {
                $this->carts->remove($customerId, (int) $params['itemId']);
                http_response_code(204);
            }
        } catch (CartException $error) {
            Response::error($error->status, $error->getMessage());
        }
    }

    private function readInput(bool $add): ?array
    {
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            Response::error(415, 'Cart writes require Content-Type: application/json.');
            return null;
        }
        try { $body = json_decode((string) file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { Response::error(400, 'Malformed JSON body.'); return null; }
        if (!$body instanceof \stdClass) { Response::error(400, 'The request body must be a JSON object.'); return null; }
        $input = (array) $body;
        $fields = $add ? ['productId' => 4294967295, 'quantity' => 1000000] : ['quantity' => 1000000];
        $errors = [];
        foreach ($input as $field => $value) {
            if (!array_key_exists($field, $fields)) { $errors[$field] = 'This field is not allowed.'; }
        }
        foreach ($fields as $field => $max) {
            if (!isset($input[$field]) || !is_int($input[$field]) || $input[$field] < 1 || $input[$field] > $max) {
                $errors[$field] = "Use an integer from 1 to $max.";
            }
        }
        if ($errors !== []) { Response::error(422, 'Validation failed.', [], $errors); return null; }
        return $input;
    }
}
