<?php

use App\Kernel;

if (!is_file(dirname(__DIR__).'/vendor/autoload_runtime.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dependencies are missing. Run: composer install";
    exit;
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
