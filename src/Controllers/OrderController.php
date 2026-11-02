<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Env;
use Foodwell\Http\OrderException;
use Foodwell\Http\Response;
use Foodwell\Repositories\OrderRepository;
use JsonException;

final class OrderController
{
    public function __construct(private OrderRepository $orders) {}

    public function create(array $params): void
    {
        if (!$this->demoEnabled()) {
            Response::error(
                403,
                'Order demo is disabled. Authentication and ownership enforcement remain planned.'
            );
            return;
        }

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
        if (!$this->demoEnabled()) {
            Response::error(
                403,
                'Order demo is disabled. Authentication and ownership enforcement remain planned.'
            );
            return;
        }

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

    private function demoEnabled(): bool
    {
        return Env::get('APP_CART_DEMO', '0') === '1'
            && in_array(
                $_SERVER['REMOTE_ADDR'] ?? '',
                ['127.0.0.1', '::1'],
                true
            );
    }

    private function readInput(): ?array
    {
        $contentType = strtolower(
            trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])
        );

        if ($contentType !== 'application/json') {
            Response::error(
                415,
                'Order creation requires Content-Type: application/json.'
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

        $input = (array) $body;
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