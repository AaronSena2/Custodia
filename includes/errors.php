<?php
/**
 * A single HTTP-flavored exception used throughout includes/ and actions/.
 * Action endpoints (actions/*.php) catch this at the top level and turn it
 * into a JSON {error: message} response with the matching status code —
 * see includes/action_bootstrap.php.
 */
class CustodiaHttpException extends RuntimeException
{
    public int $status;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function custodia_not_found(string $message): CustodiaHttpException
{
    return new CustodiaHttpException(404, $message);
}

function custodia_forbidden(string $message): CustodiaHttpException
{
    return new CustodiaHttpException(403, $message);
}

function custodia_bad_request(string $message): CustodiaHttpException
{
    return new CustodiaHttpException(400, $message);
}
