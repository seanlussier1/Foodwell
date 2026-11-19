<?php
declare(strict_types=1);

namespace Foodwell\Validation;

/**
 * Checks the body of POST /products/{id}/inventory-adjustments.
 *
 * Example of a good body:
 *   {"quantityChange": 12, "reason": "restock", "note": "Tuesday delivery"}
 *
 * This class only looks at the body itself. Whether the product exists, and
 * whether the new stock would go below zero, is checked later by the
 * controller, because it needs the database for that.
 */
final class InventoryAdjustmentInput
{
    // The same ceiling ProductInput uses for the opening stock, so a product
    // can never end up with more stock than it could have been created with.
    public const MAX_STOCK = 1000000;

    private const MAX_NOTE = 500; // same size as the note column in the database

    // Which way each reason is allowed to move the stock:
    //   restock    -> stock goes up   (we received goods)
    //   damage     -> stock goes down (goods were thrown out)
    //   correction -> either way      (a count was wrong and we fix it)
    public const REASONS = ['restock', 'damage', 'correction'];

    /**
     * @return array{data: array, errors: array<string,string>}
     *         "errors" is empty when the body is fine.
     */
    public static function validate(array $input): array
    {
        $data = [];
        $errors = [];

        // 1. Refuse any field we don't know about. This stops a client from
        //    trying to choose the actor, the stock balance or the date itself:
        //    the server always works those out on its own.
        foreach (array_keys($input) as $field) {
            if (!in_array($field, ['quantityChange', 'reason', 'note'], true)) {
                $errors[$field] = 'This field is not allowed. Only quantityChange, reason and note can be sent.';
            }
        }

        // 2. quantityChange: a whole number, not zero, and not absurdly large.
        //    is_int() is strict on purpose: "5" (text) and 5.0 (decimal) are refused.
        $change = $input['quantityChange'] ?? null;
        if (!is_int($change)) {
            $errors['quantityChange'] = 'This field is required and must be a whole number.';
        } elseif ($change === 0) {
            $errors['quantityChange'] = 'Use a positive number to add stock or a negative number to remove it; 0 changes nothing.';
        } elseif (abs($change) > self::MAX_STOCK) {
            $errors['quantityChange'] = 'Use a number from -' . self::MAX_STOCK . ' to ' . self::MAX_STOCK . '.';
        } else {
            $data['quantityChange'] = $change;
        }

        // 3. reason: one of the three words the database accepts.
        $reason = $input['reason'] ?? null;
        if (!is_string($reason) || !in_array($reason, self::REASONS, true)) {
            $errors['reason'] = 'This field is required and must be one of: ' . implode(', ', self::REASONS) . '.';
        } else {
            $data['reason'] = $reason;
        }

        // 4. The reason and the direction of the change must make sense together.
        //    Only checked when both fields were valid on their own.
        if (isset($data['quantityChange'], $data['reason'])) {
            if ($data['reason'] === 'restock' && $data['quantityChange'] < 0) {
                $errors['quantityChange'] = 'A restock adds stock, so quantityChange must be positive.';
            } elseif ($data['reason'] === 'damage' && $data['quantityChange'] > 0) {
                $errors['quantityChange'] = 'Damage removes stock, so quantityChange must be negative.';
            }
        }

        // 5. note: optional. Spaces around it are removed, and an empty note
        //    is saved as "no note" (null) instead of an empty string.
        $note = $input['note'] ?? null;
        if ($note === null) {
            $data['note'] = null;
        } elseif (!is_string($note)) {
            $errors['note'] = 'The note must be text or null.';
        } else {
            $note = trim($note);
            if (mb_strlen($note) > self::MAX_NOTE) {
                $errors['note'] = 'Use at most ' . self::MAX_NOTE . ' characters.';
            } else {
                $data['note'] = $note === '' ? null : $note;
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }
}
