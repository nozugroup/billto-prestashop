<?php

namespace BillTo\PrestaShop\Api;

/**
 * Non-2xx response from the BillTo API, or a transport failure (status 0).
 */
final class ApiException extends \RuntimeException
{
    /** @var int */
    private $status;

    /** @var array<string, mixed> */
    private $body;

    /**
     * @param array<string, mixed> $body
     */
    public function __construct(string $message, int $status, array $body = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
        $this->status = $status;
        $this->body = $body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    public function isTransport(): bool
    {
        return $this->status === 0;
    }

    public function isValidation(): bool
    {
        return $this->status === 422;
    }

    public function isConflict(): bool
    {
        return $this->status === 409;
    }

    public function isPlanLimit(): bool
    {
        return $this->status === 429 && isset($this->body['usage']);
    }

    public function isRetryable(): bool
    {
        if ($this->isTransport()) {
            return true;
        }

        if ($this->status === 429) {
            return !$this->isPlanLimit();
        }

        return in_array($this->status, [500, 502, 503, 504], true);
    }

    /** @return string[] */
    public function validationMessages(): array
    {
        $lines = [];
        $errors = isset($this->body['errors']) && is_array($this->body['errors']) ? $this->body['errors'] : [];

        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $lines[] = $field . ': ' . (is_scalar($message) ? (string) $message : json_encode($message));
            }
        }

        return $lines;
    }
}
