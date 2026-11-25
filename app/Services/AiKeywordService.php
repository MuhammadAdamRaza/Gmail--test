<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiKeywordService
{
    private const CACHE_PREFIX = 'gmail:kw:v3:';

    /**
     * Get cached 4 keywords for a single email if already extracted.
     *
     * @return array<int, string>|null
     */
    public function getCached(string $messageId): ?array
    {
        $cached = Cache::get(self::CACHE_PREFIX . $messageId);

        return is_array($cached) ? $cached : null;
    }

    /**
     * Extract 4 concise keywords for a batch of emails in a single OpenRouter API call.
     *
     * @param  array<int, array{id: string, from?: string, subject?: string, snippet?: string}>  $emails
     * @return array<string, array<int, string>>
     */
    public function extractBatch(array $emails): array
    {
        $results = [];
        $uncached = [];

        foreach ($emails as $email) {
            $id = (string) ($email['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $cached = $this->getCached($id);
            if ($cached !== null) {
                $results[$id] = $cached;
            } else {
                $uncached[] = [
                    'id' => $id,
                    'from' => mb_substr((string) ($email['from'] ?? ''), 0, 80),
                    'subject' => mb_substr((string) ($email['subject'] ?? ''), 0, 140),
                    'snippet' => mb_substr((string) ($email['snippet'] ?? ''), 0, 220),
                ];
            }
        }

        if (empty($uncached)) {
            return $results;
        }

        $apiKey = (string) config('services.openrouter.api_key');
        $apiUrl = (string) config('services.openrouter.api_url', 'https://openrouter.ai/api/v1/chat/completions');
        $model = (string) config('services.openrouter.model', 'deepseek/deepseek-chat');

        if ($apiKey === '') {
            return $results;
        }

        // Process in small chunks of 8 so each OpenRouter call is fast and never times out
        foreach (array_chunk($uncached, 8) as $chunk) {
            try {
                $promptPayload = json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'http://127.0.0.1:8000'),
                    'X-Title' => 'Gmail Workspace',
                ])->timeout(30)->post($apiUrl, [
                    'model' => $model,
                    'temperature' => 0.1,
                    'max_tokens' => 600,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Extract 2 to 6 main, high-signal keywords (1-3 words each) based on how detailed each email is: 2-3 for short/simple emails, 4 for normal emails, and 5-6 for detailed/multi-topic emails so the user understands the email without opening it. Do NOT repeat generic filler words. Return ONLY valid JSON mapping each "id" to an array of 2-6 strings. No markdown.',
                        ],
                        [
                            'role' => 'user',
                            'content' => 'Extract main keywords for each email in this JSON list: ' . $promptPayload,
                        ],
                    ],
                ]);

                if (!$response->successful()) {
                    Log::warning('OpenRouter keyword extraction failed', [
                        'status' => $response->status(),
                        'body' => mb_substr($response->body(), 0, 300),
                    ]);
                    continue;
                }

                $content = (string) data_get($response->json(), 'choices.0.message.content', '');
                $parsed = $this->parseJsonResponse($content);

                foreach ($chunk as $item) {
                    $id = $item['id'];
                    $rawKeywords = $parsed[$id] ?? null;
                    if (is_array($rawKeywords) && count($rawKeywords) > 0) {
                        $clean = array_values(array_slice(array_filter(array_map(
                            fn ($k) => trim((string) $k),
                            $rawKeywords
                        )), 0, 6));

                        if (!empty($clean)) {
                            Cache::put(self::CACHE_PREFIX . $id, $clean, now()->addDays(30));
                            $results[$id] = $clean;
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::warning('OpenRouter exception during keyword extraction', ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonResponse(string $content): array
    {
        $trimmed = trim($content);
        $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fallback: extract first JSON object if surrounding text exists
        if (preg_match('/\{.*\}/s', $trimmed, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
