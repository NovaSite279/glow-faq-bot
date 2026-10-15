<?php
declare(strict_types=1);

// TEMPORARY setup check. Shows why the model call fails. Delete this file once the bot works.

require dirname(__DIR__) . '/bootstrap.php';

use Glow\Env;

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

$base = rtrim(Env::get('LLM_BASE_URL', 'https://api.groq.com/openai/v1'), '/');
$key = Env::get('LLM_API_KEY');
$model = Env::get('LLM_MODEL', 'openai/gpt-oss-120b');

echo "PHP " . PHP_VERSION . ", curl " . (function_exists('curl_init') ? 'yes' : 'NO') . "\n";
echo ".env found: " . (is_file(dirname(__DIR__) . '/.env') ? 'yes' : 'NO') . "\n";
echo "Key: " . ($key === '' ? 'EMPTY' : substr($key, 0, 5) . '... (' . strlen($key) . ' characters)') . "\n";
echo "Base URL: $base\nModel: $model\n\n";

function call(string $url, string $key, ?array $body, int $max = 700): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key, 'x-goog-api-key: ' . $key],
    ];
    if ($body !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$status, $raw === false ? "curl error: $err" : substr((string) $raw, 0, $max)];
}

$msg = [['role' => 'user', 'content' => 'Reply with JSON only: {"reply":"hi"}']];
$tests = [
    'A. Exactly what the bot sends' => ['model' => $model, 'messages' => $msg, 'temperature' => 0.2, 'max_tokens' => 1200,
        'response_format' => ['type' => 'json_object'], 'reasoning_effort' => Env::get('LLM_REASONING_EFFORT') ?: null],
    'B. Without JSON mode or reasoning setting' => ['model' => $model, 'messages' => $msg],
];
foreach ($tests as $name => $body) {
    $body = array_filter($body, fn ($v) => $v !== null);
    [$status, $out] = call("$base/chat/completions", $key, $body);
    echo "$name: HTTP $status\n$out\n\n";
}

[$status, $out] = call("$base/models", $key, null, 200000);
preg_match_all('/"id"\s*:\s*"([^"]+)"/', $out, $m);
echo "C. Models this key can use (HTTP $status): " . ($m[1] ? implode(', ', array_slice(array_map(fn ($id) => str_replace('models/', '', $id), $m[1]), 0, 30)) : $out) . "\n";

$log = dirname(__DIR__) . '/storage/errors.log';
echo "\nD. Last bot errors:\n" . (is_file($log) ? implode('', array_slice(file($log), -8)) : "(none yet)\n");
