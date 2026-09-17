<?php

declare(strict_types=1);

namespace TelegramBanking\App\Http;

class Request
{
    public function __construct(
        private string $method,
        private string $uri,
        private array $headers,
        private string $body
    ) {}

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->uri;
    }

    public static function fromGlobals(): self
    {
        return new self(
            method: $_SERVER['REQUEST_METHOD'] ?? 'GET',
            uri: parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/api/',
            headers: getallheaders(),
            body: file_get_contents('php://input')
        );
    }
}
