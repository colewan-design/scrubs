<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use PDOException;
use RuntimeException;

abstract class Controller
{
    /**
     * The sentence to show a customer when the domain layer says no.
     *
     * The services refuse by throwing a RuntimeException whose message is
     * written for the customer — "Only 2 left in stock" — and the storefront
     * shows it as-is. But a database failure is a RuntimeException too
     * (QueryException extends PDOException), and its message is the SQL, the
     * table and column names, and the connection's host and database. That one
     * is reported, so somebody finds out, and swapped for `$fallback`.
     */
    protected function customerMessage(RuntimeException $e, string $fallback): string
    {
        if ($e instanceof PDOException) {
            report($e);

            return $fallback;
        }

        return $e->getMessage();
    }

    /**
     * The same, as a response: 422 for a refusal the customer can act on, 500
     * for a failure of ours that they cannot.
     */
    protected function refusal(RuntimeException $e, string $fallback): JsonResponse
    {
        return response()->json(
            ['message' => $this->customerMessage($e, $fallback)],
            $e instanceof PDOException ? 500 : 422,
        );
    }
}
