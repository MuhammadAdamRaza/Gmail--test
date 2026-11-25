<?php

namespace App\Services;

use App\Models\GmailAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiKeywordService
{
    private const CACHE_PREFIX = 'gmail:kw:v7:';

    /**
     * Get cached keywords for a single email if already extracted.
     *
     * @return array<int, string>|null
     */
    public function getCached(string $messageId): ?array
    {
        $cached = Cache::get(self::CACHE_PREFIX . $messageId);

        return is_array($cached) ? $cached : null;
    }

    /**
     * Extract 2-6 concise keywords for a batch of emails using their full body content.
     *
     * @param  array<int, array{id: string, from?: string, subject?: string, snippet?: string}>  $emails
     * @return array<string, array<int, string>>
     */
    public function extractBatch(array $emails, ?GmailAccount $account = null, ?GmailService $gmailService = null): array
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

        // Fetch full email bodies in one Batch API call for uncached messages
        $fullBodies = [];
        if ($account && $gmailService) {
            try {
                $fullBodies = $gmailService->getBodiesBatch(
                    $account,
                    array_column($uncached, 'id'),
                    25000
                );
            } catch (Throwable $e) {
                Log::warning('Failed to fetch full bodies for AI keywords, falling back to snippet', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $payloadItems = array_map(function (array $item) use ($fullBodies) {
            $body = $fullBodies[$item['id']] ?? $item['snippet'];

            return [
                'id' => $item['id'],
                'from' => $item['from'],
                'subject' => $item['subject'],
                'body' => $body,
            ];
        }, $uncached);

        $apiKey = (string) config('services.openrouter.api_key');
        $apiUrl = (string) config('services.openrouter.api_url', 'https://openrouter.ai/api/v1/chat/completions');
        $model = (string) config('services.openrouter.model', 'deepseek/deepseek-chat');

        if ($apiKey === '') {
            return $results;
        }

        // Process in small chunks of 8 so each OpenRouter call is fast and never times out
        foreach (array_chunk($payloadItems, 8) as $chunk) {
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
                    'max_tokens' => 2000,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Read each email\'s ENTIRE body from start to finish and extract all main keywords (1-4 words each). IMPORTANT: If an email lists multiple companies, jobs, products, or items (for example, 8 different companies hiring), you MUST include EVERY single company/item name and its main role/topic — do NOT skip companies or stop at 5-6 keywords. For short emails, give 2-3 keywords. ONLY skip email footer/UI boilerplate ("Unsubscribe", "Privacy Policy", "Manage settings", "Create job alerts", "Edit job alert", "Trademarks", "Copyright", "View in browser", "Help") and generic filler ("Job alert", "Email", "Notification"). Return ONLY valid JSON mapping each "id" to an array of strings. No markdown.',
                        ],
                        [
                            'role' => 'user',
                            'content' => 'Extract all relevant keywords from the full body of each email in this JSON list: ' . $promptPayload,
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
                        $clean = $this->filterKeywords($rawKeywords);

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
     * Remove boilerplate/footer noise and case-insensitive duplicates.
     *
     * @param  array<int, mixed>  $rawKeywords
     * @return array<int, string>
     */
    private function filterKeywords(array $rawKeywords): array
    {
        $blocked = [
            'unsubscribe',
            'privacy policy',
            'manage settings',
            'create job alerts',
            'create job alert',
            'edit job alert',
            'edit job alerts',
            'trademarks',
            'copyright',
            'terms of service',
            'terms and conditions',
            'view in browser',
            'job alert',
            'job alerts',
            'email preferences',
            'help center',
        ];

        $seen = [];
        $clean = [];

        foreach ($rawKeywords as $kw) {
            $text = trim((string) $kw);
            if ($text === '') {
                continue;
            }

            $lower = mb_strtolower($text);
            if (in_array($lower, $blocked, true) || isset($seen[$lower])) {
                continue;
            }

            $seen[$lower] = true;
            $clean[] = $text;
        }

        return $clean;
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

        if (preg_match('/\{.*\}/s', $trimmed, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
