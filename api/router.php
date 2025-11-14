<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$route = trim($path, '/');
$route = $route === '' ? 'index' : $route;

$map = [
    '' => 'index.php',
    'index' => 'index.php',
    'index.php' => 'index.php',
    'product' => 'product.php',
    'product.php' => 'product.php',
    'cart' => 'cart.php',
    'cart.php' => 'cart.php',
    'checkout' => 'checkout.php',
    'checkout.php' => 'checkout.php',
];

$script = $map[$route] ?? null;

if (!$script) {
    http_response_code(404);
    echo "Not Found";
    return;
}

require $script;
