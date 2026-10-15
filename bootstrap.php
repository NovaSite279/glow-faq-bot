<?php
declare(strict_types=1);

require __DIR__ . '/src/Env.php';
require __DIR__ . '/src/Llm.php';
require __DIR__ . '/src/Bot.php';

Glow\Env::load(__DIR__ . '/.env');

/** Builds the bot with the real LLM from .env. */
function glow_bot(): Glow\Bot
{
    $llm = Glow\Llm::fromEnv();
    return new Glow\Bot(fn (array $messages) => $llm->chat($messages), file_get_contents(__DIR__ . '/faq.md'));
}

/** Writes a handoff to storage/handoffs.log, standing in for "notify the team". */
function glow_log_handoff(string $conversationId, array $history): void
{
    $line = json_encode([
        'at' => date('c'),
        'conversation' => $conversationId,
        'messages' => $history,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents(__DIR__ . '/storage/handoffs.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}
