<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
  /** @var array<string, array<string, callable>> */
  private array $routes = [];

  public function add(string $method, string $path, callable $handler): void
  {
    // Store handlers by HTTP method and path.
    $method = strtoupper($method);
    $this->routes[$method][$path] = $handler;
  }

  public function dispatch(): void
  {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    $handler = $this->routes[$method][$path] ?? null;

    if ($handler === null) {
      http_response_code(404);
      header('Content-Type: application/json');
      echo json_encode(['error' => 'not_found']);
      return;
    }

    // Read JSON body if present.
    $raw = file_get_contents('php://input');
    $body = [];
    if (is_string($raw) && trim($raw) !== '') {
      $decoded = json_decode($raw, true);
      if (!is_array($decoded)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_json']);
        return;
      }
      $body = $decoded;
    }

    // Call your handler and return JSON.
    $result = $handler($body);

    header('Content-Type: application/json');
    echo json_encode($result);
  }
}
