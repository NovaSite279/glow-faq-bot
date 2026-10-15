<?php
declare(strict_types=1);

// POST {message} -> {reply, action}. POST {reset: true} starts a new chat.
// The conversation is kept in the PHP session, so follow-up questions work.

require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

if (!empty($input['reset'])) {
    $_SESSION = [];
    session_regenerate_id(true);
    echo json_encode(['ok' => true]);
    exit;
}

$message = trim((string) ($input['message'] ?? ''));
if ($message === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Empty message']);
    exit;
}

// Light abuse limit: 40 messages per chat, at most one every 1.5 seconds.
$_SESSION['count'] = ($_SESSION['count'] ?? 0) + 1;
$last = $_SESSION['last'] ?? 0;
$_SESSION['last'] = microtime(true);
if ($_SESSION['count'] > 40 || microtime(true) - $last < 1.5) {
    http_response_code(429);
    echo json_encode(['reply' => 'Let\'s slow down a little 🙂 For anything urgent, please call us on ' . Glow\Bot::PHONE . '.', 'action' => 'answer']);
    exit;
}

try {
    $bot = glow_bot();
} catch (Throwable $e) {
    error_log('Glow bot setup: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['reply' => 'The assistant isn\'t set up yet. Please call us on ' . Glow\Bot::PHONE . '.', 'action' => 'error']);
    exit;
}

$result = $bot->respond($_SESSION['history'] ?? [], $message);
$_SESSION['history'] = $result['history'];

if ($result['action'] === 'handoff') {
    glow_log_handoff(session_id(), $result['history']);
}

echo json_encode(['reply' => $result['reply'], 'action' => $result['action']], JSON_UNESCAPED_UNICODE);
