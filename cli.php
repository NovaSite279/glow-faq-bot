<?php
declare(strict_types=1);

// Chat with the bot in a terminal: php cli.php

require __DIR__ . '/bootstrap.php';

$bot = glow_bot();
$history = [];
echo "Glow Hair Studio assistant. Type a message, or 'quit' to stop.\n\n";
while (true) {
    $line = readline('You: ');
    if ($line === false || in_array(strtolower(trim($line)), ['quit', 'exit'], true)) {
        break;
    }
    $r = $bot->respond($history, $line);
    $history = $r['history'];
    echo "Glow: {$r['reply']}  [{$r['action']}]\n\n";
}
