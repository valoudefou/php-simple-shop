<?php
// Suppress deprecated warnings from Flagship SDK on PHP 8.1+
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$sessionSavePath = ini_get('session.save_path');
if (!$sessionSavePath || !is_dir($sessionSavePath) || !is_writable($sessionSavePath)) {
    $fallbackDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'simple-php-shop-sessions';
    if (!is_dir($fallbackDir)) {
        @mkdir($fallbackDir, 0777, true);
    }
    if (is_dir($fallbackDir) && is_writable($fallbackDir)) {
        session_save_path($fallbackDir);
    }
}

session_start();

require __DIR__ . '/vendor/autoload.php';

use Flagship\Flagship;
use Flagship\Config\DecisionApiConfig;
use Flagship\Utils\FlagshipLogManager;
use Flagship\Enum\LogLevel;

$envFile = __DIR__ . '/.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $separatorPos = strpos($line, '=');
        if ($separatorPos === false) {
            continue;
        }
        $name = trim(substr($line, 0, $separatorPos));
        $value = trim(substr($line, $separatorPos + 1));
        if ($name === '') {
            continue;
        }
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
        }
        putenv($name . '=' . $value);
    }
}

$defaultEnvId = 'blrok2jb3fq008ho9c70';
$defaultApiKey = 'k0Q3wqL9GEajXlL6dw8vr4zfqxz50LIa7QAJDz8q';

$flagshipEnvId = getenv('FLAGSHIP_ENV_ID') ?: $defaultEnvId;
$flagshipApiKey = getenv('FLAGSHIP_API_KEY') ?: $defaultApiKey;

if (!function_exists('fetchSearchQueryForCountry')) {
    function fetchSearchQueryForCountry(string $countryCode = 'GB'): ?string
    {
        $endpoint = 'https://searchconsole-googleapis.vercel.app/v1/sites/https%3A%2F%2Faccu.co.uk%2F/searchAnalytics:query';
        $payload = json_encode([
            'startDate' => '2025-01-01',
            'endDate' => '2025-01-31',
            'dimensions' => ['date', 'page', 'query'],
        ]);

        $response = null;

        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => 5,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 5,
                ],
            ]);
            $response = @file_get_contents($endpoint, false, $context);
        }

        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['search_console_rows'])) {
            return null;
        }

        foreach ($data['search_console_rows'] as $row) {
            if (($row['country'] ?? '') === $countryCode && !empty($row['query'])) {
                return $row['query'];
            }
        }

        return null;
    }
}

if (!isset($_SESSION['checkout_flow_preference'])) {
    $_SESSION['checkout_flow_preference'] = 0;
}

if (isset($_GET['checkout_flow'])) {
    $requestedFlow = $_GET['checkout_flow'];
    if ($requestedFlow === '1' || $requestedFlow === 1) {
        $_SESSION['checkout_flow_preference'] = 1;
    } elseif ($requestedFlow === '0' || $requestedFlow === 0) {
        $_SESSION['checkout_flow_preference'] = 0;
    }
}

if (!function_exists('currentCheckoutFlowPreference')) {
    function currentCheckoutFlowPreference(): int
    {
        return isset($_SESSION['checkout_flow_preference'])
            ? (int)$_SESSION['checkout_flow_preference']
            : 0;
    }
}

if (!function_exists('flagshipVisitorId')) {
    function flagshipVisitorId(): string
    {
        if (!isset($_SESSION['flagship_visitor_id'])) {
            try {
                $_SESSION['flagship_visitor_id'] = 'visitor_' . bin2hex(random_bytes(8));
            } catch (Exception $e) {
                $_SESSION['flagship_visitor_id'] = 'visitor_' . uniqid();
            }
        }
        return $_SESSION['flagship_visitor_id'];
    }
}

if (!function_exists('transactionLogPath')) {
    function transactionLogPath(): string
    {
        static $resolvedPath = null;
        if ($resolvedPath) {
            return $resolvedPath;
        }

        $projectLog = __DIR__ . '/transactions.log';
        $projectWritable = file_exists($projectLog)
            ? is_writable($projectLog)
            : is_writable(__DIR__);

        if ($projectWritable) {
            $resolvedPath = $projectLog;
            return $resolvedPath;
        }

        $tempBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'simple-php-shop';
        if (!is_dir($tempBase)) {
            @mkdir($tempBase, 0777, true);
        }

        $resolvedPath = $tempBase . DIRECTORY_SEPARATOR . 'transactions.log';
        return $resolvedPath;
    }
}

if (!function_exists('appendTransactionLog')) {
    function appendTransactionLog(array $transactionData): void
    {
        $path = transactionLogPath();
        $line = json_encode($transactionData) . PHP_EOL;
        if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(sprintf('Unable to write transaction log to %s', $path));
        }
    }
}

if (!isset($_SESSION['flagship_logs']) || !is_array($_SESSION['flagship_logs'])) {
    $_SESSION['flagship_logs'] = [];
}

if (!class_exists('SessionFlagshipLogManager')) {
    class SessionFlagshipLogManager extends FlagshipLogManager
    {
        private const MAX_LOGS = 200;

        public function customLog($level, string $message, array $context = []): void
        {
            parent::customLog($level, $message, $context);

            $entry = [
                'timestamp' => $this->getDateTime(),
                'level' => strtoupper((string)$level),
                'message' => (string)$message,
                'context' => $context,
            ];

            $_SESSION['flagship_logs'][] = $entry;

            if (count($_SESSION['flagship_logs']) > self::MAX_LOGS) {
                $_SESSION['flagship_logs'] = array_slice($_SESSION['flagship_logs'], -self::MAX_LOGS);
            }
        }
    }
}

// Create a log manager instance
$logger = new SessionFlagshipLogManager();

// Optional: customize Flagship SDK config
$config = (new DecisionApiConfig())
    ->setTimeout(3000)           // 3000 ms timeout
    ->setLogLevel(LogLevel::ALL) // Log everything (INFO, DEBUG, ERROR, etc.)
    ->setLogManager($logger);    // Attach the logger

// Initialize Flagship SDK
Flagship::start(
    $flagshipEnvId,
    $flagshipApiKey,
    $config
);
