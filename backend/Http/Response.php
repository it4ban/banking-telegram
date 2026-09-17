<?php

declare(strict_types=1);

namespace TelegramBanking\App\Http;

class Response
{
    public function __construct(
        private mixed $data,
        private int $statusCode = 200
    ) {}

    public static function json(mixed $data, int $statusCode = 200): self
    {
        return new self($data, $statusCode);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            $this->data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
