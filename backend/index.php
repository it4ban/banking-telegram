<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use TelegramBanking\App\Http\Request;
use TelegramBanking\App\Http\Response;
use TelegramBanking\App\Router\Router;

$router = new Router;

$router->get('/api/', fn($request, $params) => Response::json([
    'message' => "hi"
], 200));

$router->get('/api/{param1}/{param2}/', fn($request, $params) => Response::json([
    'message' => "hi",
    'params' => $params
], 200));

$response = $router->dispatch(
    Request::fromGlobals()
);

$response->send();
