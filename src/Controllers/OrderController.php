<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Http\OrderException;
use Foodwell\Http\Response;
use Foodwell\Repositories\OrderRepository;
use JsonException;

final class OrderController
{
    public function __construct(private OrderRepository $orders) {}

    public function create(array $params): void
    {
        $input = $this->readInput();

        if ($input === null) {
            return;
        }

        try {
            $order = $this->orders->create(
                $input['customerId'],
                $input['deliveryAddress'],
                $input['deliverySlotId'],
                $input['addressId']
            );

            Response::json(201, $order);
        } catch (OrderException $error) {
            Response::error($error->statusCode, $error->getMessage());
        }
    }

    public function show(array $params): void
    {
        $id = filter_var(
            $params['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 4294967295]]
        );

        if ($id === false) {
            Response::error(404, 'Order not found.');
            return;
        }

        try {
            Response::json(200, $this->orders->find((int) $id));
        } catch (OrderException $error) {
            Response::error($error->statusCode, $error->getMessage());
        }
    }

    public function updateStatus(array $params): void
    {
        $id = filter_var($params['id'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 4294967295],
        ]);
        if ($id === false) {
            Response::error(404, 'Order not found.');
            return;
        }

        $input = $this->readJsonObject('Order status updates');
        if ($input === null) {
            return;
        }
        if (
            count($input) !== 1 || !is_string($input['status'] ?? null)
            || !OrderRepository::isStatus($input['status'])
        ) {
            Response::error(422, 'Provide only a valid order status.');
            return;
        }

        try {
            Response::json(200, $this->orders->updateStatus($id, $input['status'], $params['actorId']));
        } catch (OrderException $error) {
            Response::error($error->statusCode, $error->getMessage());
        }
    }

    public function customerHistory(array $params): void
    {
        $id = filter_var($params['id'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 4294967295],
        ]);
        if ($id === false) {
            Response::error(404, 'Customer not found.');
            return;
        }
        $status = $_GET['status'] ?? null;
        if ($status !== null && (!is_string($status) || !OrderRepository::isStatus($status))) {
            Response::error(400, 'Invalid order status filter.');
            return;
        }
        $paging = $this->readPaging();
        if ($paging === null) {
            return;
        }

        try {
            Response::json(200, $this->orders->listForCustomer($id, $status, ...$paging));
        } catch (OrderException $error) {
            Response::error($error->statusCode, $error->getMessage());
        }
    }

    public function statusHistory(array $params): void
    {
        $paging = $this->readPaging();
        if ($paging !== null) {
            Response::json(200, ['data' => $this->orders->listStatusEvents((int) $params['id'], ...$paging)]);
        }
    }

    private function readPaging(): ?array
    {
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 4294967295],
        ]);
        $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        if ($page === false || $limit === false) {
            Response::error(400, 'Use a positive page number and a limit from 1 to 100.');
            return null;
        }
        return [$page, $limit];
    }

    private function readJsonObject(string $operation): ?array
    {
        $contentType = strtolower(
            trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])
        );

        if ($contentType !== 'application/json') {
            Response::error(
                415,
                "$operation requires Content-Type: application/json."
            );
            return null;
        }

        try {
            $body = json_decode(
                (string) file_get_contents('php://input'),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $error) {
            Response::error(400, 'Malformed JSON body.');
            return null;
        }

        if (!$body instanceof \stdClass) {
            Response::error(400, 'The request body must be a JSON object.');
            return null;
        }

        return (array) $body;
    }

    private function readInput(): ?array
    {
        $input = $this->readJsonObject('Order creation');
        if ($input === null) {
            return null;
        }
        $allowedFields = [
            'customerId',
            'deliveryAddress',
            'addressId',
            'deliverySlotId',
        ];
        $errors = [];

        foreach ($input as $field => $value) {
            if (!in_array($field, $allowedFields, true)) {
                $errors[$field] = 'This field is not allowed.';
            }
        }

        $customerId = $input['customerId'] ?? null;

        if (
            !is_int($customerId)
            || $customerId < 1
            || $customerId > 4294967295
        ) {
            $errors['customerId'] = 'Use a valid positive integer.';
        }

        $hasDeliveryAddress = array_key_exists('deliveryAddress', $input);
        $hasAddressId = array_key_exists('addressId', $input);

        if ($hasDeliveryAddress === $hasAddressId) {
            $errors['deliveryAddress'] =
                'Provide either deliveryAddress or addressId, but not both.';
            $errors['addressId'] =
                'Provide either deliveryAddress or addressId, but not both.';
        }

        $deliveryAddress = null;

        if ($hasDeliveryAddress) {
            $deliveryAddress = $input['deliveryAddress'];

            if (
                !is_string($deliveryAddress)
                || trim($deliveryAddress) === ''
                || strlen(trim($deliveryAddress)) > 500
            ) {
                $errors['deliveryAddress'] =
                    'Provide a non-empty delivery address of at most 500 characters.';
            } else {
                $deliveryAddress = trim($deliveryAddress);
            }
        }

        $addressId = $input['addressId'] ?? null;

        if (
            $hasAddressId
            && (
                !is_int($addressId)
                || $addressId < 1
                || $addressId > 4294967295
            )
        ) {
            $errors['addressId'] = 'Use a valid positive integer.';
        }

        $deliverySlotId = $input['deliverySlotId'] ?? null;

        if (
            array_key_exists('deliverySlotId', $input)
            && $deliverySlotId !== null
            && (
                !is_int($deliverySlotId)
                || $deliverySlotId < 1
                || $deliverySlotId > 4294967295
            )
        ) {
            $errors['deliverySlotId'] =
                'Use a valid positive integer or null.';
        }

        if ($errors !== []) {
            Response::error(422, 'Validation failed.', [], $errors);
            return null;
        }

        return [
            'customerId' => $customerId,
            'deliveryAddress' => $deliveryAddress,
            'addressId' => $addressId,
            'deliverySlotId' => $deliverySlotId,
        ];
    }
}
