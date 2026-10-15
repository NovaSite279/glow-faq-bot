<?php
declare(strict_types=1);

namespace Glow;

/**
 * Tiny client for any OpenAI-compatible chat endpoint.
 * Works with Groq (free tier) and Google Gemini (free tier) without code changes:
 * only LLM_BASE_URL, LLM_MODEL and LLM_API_KEY in .env differ.
 */
final class Llm
{
    private bool $jsonMode = true;

    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $model,
        private string $reasoningEffort = '',
        private int $timeout = 30,
    ) {
    }

    public static function fromEnv(): self
    {
        $key = Env::get('LLM_API_KEY');
        if ($key === '') {
            throw new \RuntimeException('LLM_API_KEY is missing. Copy .env.example to .env and add your key.');
        }
        return new self(
            rtrim(Env::get('LLM_BASE_URL', 'https://api.groq.com/openai/v1'), '/'),
            $key,
            Env::get('LLM_MODEL', 'openai/gpt-oss-120b'),
            Env::get('LLM_REASONING_EFFORT', ''),
        );
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return string the model's text
     */
    public function chat(array $messages): string
    {
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.2,
            'max_tokens' => 1200, // reasoning models count their thinking here too
        ];
        if ($this->jsonMode) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        if ($this->reasoningEffort !== '') {
            $body['reasoning_effort'] = $this->reasoningEffort;
        }

        [$status, $raw] = $this->post($body);

        // A few providers/models reject JSON mode. Retry once without it;
        // the prompt asks for JSON anyway and Bot parses leniently.
        if ($status === 400 && $this->jsonMode && stripos($raw, 'response_format') !== false) {
            $this->jsonMode = false;
            unset($body['response_format']);
            [$status, $raw] = $this->post($body);
        }

        // Some models reject the reasoning setting ("Thinking level ... is not supported").
        // Drop it and retry, so a model change in .env can't take the bot down.
        if ($status === 400 && isset($body['reasoning_effort']) && preg_match('/thinking|reasoning/i', $raw)) {
            $this->reasoningEffort = '';
            unset($body['reasoning_effort']);
            [$status, $raw] = $this->post($body);
        }

        // Providers retire models. If the configured one is gone, pick the best one this key can use.
        if ($status === 404 && str_contains($raw, 'model')) {
            $replacement = $this->pickAvailableModel();
            if ($replacement !== null && $replacement !== $this->model) {
                error_log("Glow bot: model {$this->model} not available, using $replacement. Update LLM_MODEL in .env.");
                $this->model = $body['model'] = $replacement;
                [$status, $raw] = $this->post($body);
            }
        }

        // Free tiers allow only a few requests a minute. On "too many requests" or a
        // temporary provider error, wait as long as the provider asks (up to 12 s) and try again, twice at most.
        for ($try = 0; $try < 2 && in_array($status, [429, 500, 502, 503], true); $try++) {
            sleep(self::retryDelay($raw));
            [$status, $raw] = $this->post($body);
        }

        if ($status !== 200) {
            throw new \RuntimeException("LLM HTTP $status: " . substr($raw, 0, 300));
        }
        $data = json_decode($raw, true);
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('LLM returned no text: ' . substr($raw, 0, 300));
        }
        return $text;
    }

    /** Seconds to wait before retrying, from the provider's message (Groq: "try again in 1.5s", Google: "retryDelay": "7s"). */
    public static function retryDelay(string $raw): int
    {
        if (preg_match('/try again in (?:(\d+)m)?([\d.]+)(ms|s)/i', $raw, $m)) {
            $secs = ((int) $m[1]) * 60 + ($m[3] === 'ms' ? (float) $m[2] / 1000 : (float) $m[2]);
        } elseif (preg_match('/"retryDelay"\s*:\s*"([\d.]+)/', $raw, $m)) {
            $secs = (float) $m[1];
        } else {
            $secs = 2;
        }
        return (int) min(12, max(1, ceil($secs)));
    }

    /** Asks the provider which models this key can use and returns the first good chat model. */
    private function pickAvailableModel(): ?string
    {
        $ch = curl_init($this->baseUrl . '/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey],
        ]);
        $raw = (string) curl_exec($ch);
        curl_close($ch);
        $ids = array_map(fn ($m) => str_replace('models/', '', (string) ($m['id'] ?? '')), json_decode($raw, true)['data'] ?? []);
        $preferred = ['openai/gpt-oss-120b', 'llama-3.3-70b-versatile', 'openai/gpt-oss-20b', 'gemini-3.5-flash', 'gemini-2.5-flash', 'llama-3.1-8b-instant'];
        foreach ($preferred as $id) {
            if (in_array($id, $ids, true)) {
                return $id;
            }
        }
        foreach ($ids as $id) {
            if (!preg_match('/whisper|tts|guard|embed|image|audio|transcribe|vision/i', $id)) {
                return $id;
            }
        }
        return null;
    }

    /** @return array{0: int, 1: string} */
    private function post(array $body): array
    {
        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ], str_contains($this->baseUrl, 'googleapis.com')
                ? ['x-goog-api-key: ' . $this->apiKey] // Google's newer AI Studio keys ("AQ....") are read from this header
                : []),
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('Could not reach the LLM: ' . $err);
        }
        return [$status, (string) $raw];
    }
}
