<?php
declare(strict_types=1);

require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../inc/egress_helpers.php';

final class GroqProvider implements ProviderInterface
{
    /** Ajuste si tu lis la clé ailleurs (secure_store, env, etc.) */
    private function apiKey(): ?string {
        // 1) via env
        $k = getenv('GROQ_API_KEY');
        if ($k) return $k;

        // 2) via secrets_providers.json (ta structure)
        $sp = vx_json_load_assoc(dirname(__DIR__) . '/config/secrets_providers.json');
        if (isset($sp['groq_api_key'])) return (string)$sp['groq_api_key'];

        return null;
    }

    public function getDisplayName(): string { return 'Groq'; }
    public function getName(): string { return 'groq'; }

    public function supportedOptions(): array {
        return [
            'model' => ['type'=>'select','values'=>[
                'llama-3.1-70b-versatile',
                'llama3-70b-8192',
                'mixtral-8x7b-32768'
            ]],
            'temperature' => ['type'=>'float','min'=>0,'max'=>2,'step'=>0.1,'default'=>0.2],
            'max_tokens'  => ['type'=>'int','min'=>1,'max'=>8192,'default'=>1024]
        ];
    }

    public function sendChat(string $prompt, array $opts = []): array {
        $apiKey = $this->apiKey();
        if (!$apiKey) {
            return ['ok'=>false,'error'=>'missing_groq_api_key'];
        }

        $model = $opts['model'] ?? 'llama-3.1-70b-versatile';
        $temperature = isset($opts['temperature']) ? (float)$opts['temperature'] : 0.2;
        $maxTokens   = isset($opts['max_tokens']) ? (int)$opts['max_tokens'] : 1024;

        // Groq est compatible "OpenAI-like chat completions"
        $url = 'https://api.groq.com/openai/v1/chat/completions';
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $opts['system'] ?? ''],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
            'stream'      => false
        ];

        $headers = [
            "Authorization: Bearer {$apiKey}",
            "Content-Type: application/json"
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 30
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($resp === false) {
            return ['ok'=>false,'code'=>$code,'error'=>curl_error($ch)];
        }
        $j = json_decode((string)$resp, true);
        if ($code < 200 || $code >= 300) {
            return ['ok'=>false,'code'=>$code,'error'=>$j['error']['message'] ?? 'groq_api_error','raw'=>$resp];
        }

        $text = $j['choices'][0]['message']['content'] ?? '';
        return [
            'ok'=>true,
            'model'=>$j['model'] ?? $model,
            'usage'=>$j['usage'] ?? null,
            'text'=>$text,
            'raw'=>$j
        ];
    }

    /* — Extensions multi-bots (non utilisées par Groq) — */
    public function send(array $payload): array {
        return ['ok'=>false,'reason'=>'not_supported'];
    }
    public function verify(array $server, string $rawBody): array {
        return ['ok'=>false,'reason'=>'not_supported'];
    }
}
