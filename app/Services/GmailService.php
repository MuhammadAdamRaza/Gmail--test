<?php

namespace App\Services;

use App\Models\GmailAccount;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\ModifyMessageRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class GmailService
{
    /**
     * Create a configured Google API client instance.
     */
    public function makeClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect'));
        $client->setAccessType('offline');

        return $client;
    }

    /**
     * Return a valid access token, refreshing it automatically if expired.
     */
    public function getValidToken(GmailAccount $account): string
    {
        if ($account->expires_at && $account->expires_at->copy()->subMinute()->isFuture()) {
            return $account->access_token;
        }

        if (empty($account->refresh_token)) {
            throw new RuntimeException('Refresh token is missing. Please reconnect your Gmail account.');
        }

        $client = $this->makeClient();
        $client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
        ]);

        $newToken = $client->fetchAccessTokenWithRefreshToken($account->refresh_token);

        if (isset($newToken['error'])) {
            $message = $newToken['error_description'] ?? $newToken['error'];
            throw new RuntimeException('Failed to refresh Google access token: ' . $message);
        }

        if (empty($newToken['access_token'])) {
            throw new RuntimeException('Google did not return a new access token.');
        }

        $account->update([
            'access_token' => $newToken['access_token'],
            'refresh_token' => $newToken['refresh_token'] ?? $account->refresh_token,
            'expires_at' => now()->addSeconds((int) ($newToken['expires_in'] ?? 3600)),
        ]);

        return $account->access_token;
    }

    /**
     * Get an authenticated Gmail API service instance.
     */
    public function getGmailService(GmailAccount $account): Gmail
    {
        $accessToken = $this->getValidToken($account);

        $client = $this->makeClient();
        $client->setAccessToken([
            'access_token' => $accessToken,
            'refresh_token' => $account->refresh_token,
        ]);

        return new Gmail($client);
    }

    /**
     * Fetch messages for a folder/label using Gmail Batch API for high speed.
     *
     * @return array{
     *     messages: array<int, array<string, mixed>>,
     *     nextPageToken: ?string,
     *     resultSizeEstimate: int
     * }
     */
    public function listMessages(
        GmailAccount $account,
        string $label = 'INBOX',
        ?string $query = null,
        ?string $pageToken = null,
        int $maxResults = 50,
        bool $forceRefresh = false
    ): array {
        $cacheVersion = (int) Cache::get($this->versionKey($account), 1);
        $cacheKey = sprintf(
            'gmail:list:%d:v%d:%s:%s:%s:%d',
            $account->id,
            $cacheVersion,
            $label,
            md5((string) $query),
            (string) $pageToken,
            $maxResults
        );

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addSeconds(45), function () use ($account, $label, $query, $pageToken, $maxResults) {
            $gmail = $this->getGmailService($account);

            $params = [
                'maxResults' => min(max($maxResults, 10), 100),
            ];

            $allowedLabels = ['INBOX', 'SENT', 'STARRED', 'DRAFT', 'SPAM', 'TRASH'];
            if ($label !== 'ALL' && in_array($label, $allowedLabels, true)) {
                $params['labelIds'] = [$label];
            }

            if ($query !== null && trim($query) !== '') {
                $params['q'] = trim($query);
            }

            if ($pageToken !== null && $pageToken !== '') {
                $params['pageToken'] = $pageToken;
            }

            $response = $gmail->users_messages->listUsersMessages('me', $params);
            $items = $response->getMessages() ?? [];

            $messages = [];
            if (!empty($items)) {
                $client = $gmail->getClient();

                // Batch up to 50 metadata requests per HTTP call for maximum speed
                foreach (array_chunk($items, 50) as $chunk) {
                    $client->setUseBatch(true);
                    $batch = $gmail->createBatch();

                    foreach ($chunk as $item) {
                        $batch->add(
                            $gmail->users_messages->get('me', $item->getId(), [
                                'format' => 'metadata',
                                'metadataHeaders' => ['Subject', 'From', 'To', 'Date'],
                            ]),
                            $item->getId()
                        );
                    }

                    $batchResults = $batch->execute();
                    $client->setUseBatch(false);

                    foreach ($chunk as $item) {
                        $detail = $batchResults['response-' . $item->getId()] ?? null;
                        if (!$detail instanceof Message) {
                            continue;
                        }

                        $headers = $this->extractHeaders($detail->getPayload()?->getHeaders() ?? []);
                        $labelIds = $detail->getLabelIds() ?? [];
                        $from = $headers['from'] ?? '(Unknown Sender)';

                        $messages[] = [
                            'id' => $detail->getId(),
                            'threadId' => $detail->getThreadId(),
                            'subject' => $headers['subject'] ?? '(No Subject)',
                            'from' => $from,
                            'fromName' => $this->parseSenderName($from),
                            'to' => $headers['to'] ?? '',
                            'date' => $this->formatDate($headers['date'] ?? ''),
                            'snippet' => html_entity_decode((string) $detail->getSnippet(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                            'isUnread' => in_array('UNREAD', $labelIds, true),
                            'isStarred' => in_array('STARRED', $labelIds, true),
                            'isTrash' => in_array('TRASH', $labelIds, true),
                        ];
                    }
                }
            }

            return [
                'messages' => $messages,
                'nextPageToken' => $response->getNextPageToken(),
                'resultSizeEstimate' => (int) $response->getResultSizeEstimate(),
            ];
        });
    }

    /**
     * Fetch decoded plain-text bodies for multiple message IDs in a single Batch API call.
     *
     * @param  array<int, string>  $messageIds
     * @return array<string, string>
     */
    public function getBodiesBatch(GmailAccount $account, array $messageIds, int $maxLength = 25000): array
    {
        $ids = array_values(array_unique(array_filter($messageIds)));
        if (empty($ids)) {
            return [];
        }

        $gmail = $this->getGmailService($account);
        $client = $gmail->getClient();
        $bodies = [];

        foreach (array_chunk($ids, 25) as $chunk) {
            $client->setUseBatch(true);
            $batch = $gmail->createBatch();

            foreach ($chunk as $id) {
                $batch->add(
                    $gmail->users_messages->get('me', $id, ['format' => 'full']),
                    $id
                );
            }

            $batchResults = $batch->execute();
            $client->setUseBatch(false);

            foreach ($chunk as $id) {
                $msg = $batchResults['response-' . $id] ?? null;
                if (!$msg instanceof Message) {
                    continue;
                }

                $extracted = $this->extractBodies($msg->getPayload());
                $text = $extracted['plain'] !== null && trim($extracted['plain']) !== ''
                    ? $extracted['plain']
                    : ($extracted['html'] !== null ? $this->htmlToText($extracted['html']) : (string) $msg->getSnippet());

                $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
                $bodies[$id] = mb_substr($text, 0, $maxLength);
            }
        }

        return $bodies;
    }

    /**
     * Invalidate cached inbox lists for this account after mutations or manual refresh.
     */
    public function clearListCache(GmailAccount $account): void
    {
        $key = $this->versionKey($account);
        $current = (int) Cache::get($key, 1);
        Cache::put($key, $current + 1, now()->addHours(12));
    }

    /**
     * Fetch a single message plus all messages in its conversation thread.
     *
     * @return array<string, mixed>
     */
    public function getMessageDetail(GmailAccount $account, string $messageId): array
    {
        $gmail = $this->getGmailService($account);
        $message = $gmail->users_messages->get('me', $messageId, [
            'format' => 'full',
        ]);

        $primary = $this->parseFullMessage($message);

        if ($primary['isUnread']) {
            try {
                $modify = new ModifyMessageRequest();
                $modify->setRemoveLabelIds(['UNREAD']);
                $gmail->users_messages->modify('me', $messageId, $modify);
                $primary['isUnread'] = false;
                $this->clearListCache($account);
            } catch (\Throwable) {
                // Ignore if token only has readonly scope
            }
        }

        $threadMessages = [$primary];
        if (!empty($primary['threadId'])) {
            try {
                $thread = $gmail->users_threads->get('me', $primary['threadId'], ['format' => 'full']);
                $parsedThread = [];
                foreach ($thread->getMessages() ?? [] as $threadMsg) {
                    $parsedThread[] = $this->parseFullMessage($threadMsg);
                }
                if (count($parsedThread) > 0) {
                    $threadMessages = $parsedThread;
                }
            } catch (\Throwable) {
                // Fallback to single message if thread fetch fails
            }
        }

        $primary['threadMessages'] = $threadMessages;

        return $primary;
    }

    /**
     * Toggle Starred status on a message.
     */
    public function toggleStar(GmailAccount $account, string $messageId, bool $star): void
    {
        $gmail = $this->getGmailService($account);
        $modify = new ModifyMessageRequest();
        if ($star) {
            $modify->setAddLabelIds(['STARRED']);
        } else {
            $modify->setRemoveLabelIds(['STARRED']);
        }
        $gmail->users_messages->modify('me', $messageId, $modify);
        $this->clearListCache($account);
    }

    /**
     * Mark a message as read or unread.
     */
    public function toggleRead(GmailAccount $account, string $messageId, bool $markAsRead): void
    {
        $gmail = $this->getGmailService($account);
        $modify = new ModifyMessageRequest();
        if ($markAsRead) {
            $modify->setRemoveLabelIds(['UNREAD']);
        } else {
            $modify->setAddLabelIds(['UNREAD']);
        }
        $gmail->users_messages->modify('me', $messageId, $modify);
        $this->clearListCache($account);
    }

    /**
     * Archive a message (remove INBOX label).
     */
    public function archiveMessage(GmailAccount $account, string $messageId): void
    {
        $gmail = $this->getGmailService($account);
        $modify = new ModifyMessageRequest();
        $modify->setRemoveLabelIds(['INBOX']);
        $gmail->users_messages->modify('me', $messageId, $modify);
        $this->clearListCache($account);
    }

    /**
     * Move a message to Trash.
     */
    public function trashMessage(GmailAccount $account, string $messageId): void
    {
        $gmail = $this->getGmailService($account);
        $gmail->users_messages->trash('me', $messageId);
        $this->clearListCache($account);
    }

    /**
     * Restore a message from Trash.
     */
    public function untrashMessage(GmailAccount $account, string $messageId): void
    {
        $gmail = $this->getGmailService($account);
        $gmail->users_messages->untrash('me', $messageId);
        $this->clearListCache($account);
    }

    /**
     * Download an attachment's binary data from Gmail.
     *
     * @return array{data: string, filename: string, mimeType: string}
     */
    public function getAttachment(GmailAccount $account, string $messageId, string $attachmentId, string $filename, string $mimeType): array
    {
        $gmail = $this->getGmailService($account);
        $attachment = $gmail->users_messages_attachments->get('me', $messageId, $attachmentId);

        return [
            'data' => $this->decodeBase64Url((string) $attachment->getData()),
            'filename' => $filename ?: 'attachment',
            'mimeType' => $mimeType ?: 'application/octet-stream',
        ];
    }

    /**
     * Send a new email message (supports CC, BCC, and file attachments).
     *
     * @param  array<int, UploadedFile>  $attachments
     */
    public function sendNewMessage(
        GmailAccount $account,
        string $to,
        string $subject,
        string $body,
        ?string $cc = null,
        ?string $bcc = null,
        array $attachments = []
    ): void {
        $gmail = $this->getGmailService($account);

        $rawMessage = $this->buildMimeMessage(
            to: $to,
            subject: $subject,
            body: $body,
            cc: $cc,
            bcc: $bcc,
            attachments: $attachments
        );

        $gmailMessage = new Message();
        $gmailMessage->setRaw($this->encodeBase64Url($rawMessage));

        $gmail->users_messages->send('me', $gmailMessage);
        $this->clearListCache($account);
    }

    /**
     * Send a reply (or Reply All) to an existing Gmail message in the same thread.
     *
     * @param  array<int, UploadedFile>  $attachments
     */
    public function sendReply(
        GmailAccount $account,
        string $messageId,
        string $replyText,
        bool $replyAll = false,
        array $attachments = []
    ): void {
        $original = $this->getMessageDetail($account, $messageId);
        $gmail = $this->getGmailService($account);

        $to = $original['replyTo'] ?: $original['from'];
        $cc = null;

        if ($replyAll) {
            $recipients = array_filter(array_map('trim', explode(',', ($original['to'] ?? '') . ',' . ($original['cc'] ?? ''))));
            $filtered = array_filter($recipients, fn (string $addr) => !str_contains(strtolower($addr), strtolower($account->email)));
            if (!empty($filtered)) {
                $cc = implode(', ', array_unique($filtered));
            }
        }

        $subject = $original['subject'];
        if (!preg_match('/^Re:/i', $subject)) {
            $subject = 'Re: ' . $subject;
        }

        $extraHeaders = [];
        if (!empty($original['messageId'])) {
            $extraHeaders[] = 'In-Reply-To: ' . $original['messageId'];
            $references = trim(($original['references'] ? $original['references'] . ' ' : '') . $original['messageId']);
            $extraHeaders[] = 'References: ' . $references;
        }

        $rawMessage = $this->buildMimeMessage(
            to: $to,
            subject: $subject,
            body: $replyText,
            cc: $cc,
            extraHeaders: $extraHeaders,
            attachments: $attachments
        );

        $gmailMessage = new Message();
        $gmailMessage->setRaw($this->encodeBase64Url($rawMessage));
        $gmailMessage->setThreadId($original['threadId']);

        $gmail->users_messages->send('me', $gmailMessage);
        $this->clearListCache($account);
    }

    /**
     * Forward an existing message to a new recipient.
     */
    public function forwardMessage(GmailAccount $account, string $messageId, string $to, string $note = ''): void
    {
        $original = $this->getMessageDetail($account, $messageId);
        $gmail = $this->getGmailService($account);

        $subject = $original['subject'];
        if (!preg_match('/^Fwd:/i', $subject)) {
            $subject = 'Fwd: ' . $subject;
        }

        $forwardedBody = ($note !== '' ? $note . "\r\n\r\n" : '')
            . "---------- Forwarded message ---------\r\n"
            . 'From: ' . $original['from'] . "\r\n"
            . 'Date: ' . $original['date'] . "\r\n"
            . 'Subject: ' . $original['subject'] . "\r\n"
            . 'To: ' . ($original['to'] ?? '') . "\r\n\r\n"
            . $original['plainBody'];

        $rawMessage = $this->buildMimeMessage(
            to: $to,
            subject: $subject,
            body: $forwardedBody
        );

        $gmailMessage = new Message();
        $gmailMessage->setRaw($this->encodeBase64Url($rawMessage));

        $gmail->users_messages->send('me', $gmailMessage);
        $this->clearListCache($account);
    }

    private function versionKey(GmailAccount $account): string
    {
        return 'gmail:list:version:' . $account->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFullMessage(Message $message): array
    {
        $payload = $message->getPayload();
        $headers = $this->extractHeaders($payload?->getHeaders() ?? []);
        $bodies = $this->extractBodies($payload);
        $attachments = $this->extractAttachments($payload);
        $labelIds = $message->getLabelIds() ?? [];

        $plainBody = $bodies['plain'] !== null && trim($bodies['plain']) !== ''
            ? $bodies['plain']
            : null;

        $htmlBody = $bodies['html'] !== null && trim($bodies['html']) !== ''
            ? $this->sanitizeHtml($bodies['html'])
            : null;

        if ($plainBody === null && $htmlBody !== null) {
            $plainBody = $this->htmlToText($htmlBody);
        } elseif ($plainBody === null) {
            $plainBody = html_entity_decode((string) $message->getSnippet(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $from = $headers['from'] ?? '(Unknown Sender)';

        return [
            'id' => $message->getId(),
            'threadId' => $message->getThreadId(),
            'subject' => $headers['subject'] ?? '(No Subject)',
            'from' => $from,
            'fromName' => $this->parseSenderName($from),
            'to' => $headers['to'] ?? '',
            'cc' => $headers['cc'] ?? '',
            'replyTo' => $headers['reply-to'] ?? $from,
            'date' => $this->formatDate($headers['date'] ?? '', true),
            'messageId' => $headers['message-id'] ?? '',
            'references' => $headers['references'] ?? '',
            'plainBody' => $plainBody,
            'htmlBody' => $htmlBody,
            'attachments' => $attachments,
            'labelIds' => $labelIds,
            'isUnread' => in_array('UNREAD', $labelIds, true),
            'isStarred' => in_array('STARRED', $labelIds, true),
            'isTrash' => in_array('TRASH', $labelIds, true),
        ];
    }

    /**
     * @param  array<int, string>  $extraHeaders
     * @param  array<int, UploadedFile>  $attachments
     */
    private function buildMimeMessage(
        string $to,
        string $subject,
        string $body,
        ?string $cc = null,
        ?string $bcc = null,
        array $extraHeaders = [],
        array $attachments = []
    ): string {
        $headers = [
            'To: ' . $to,
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
        ];

        if ($cc !== null && trim($cc) !== '') {
            $headers[] = 'Cc: ' . trim($cc);
        }
        if ($bcc !== null && trim($bcc) !== '') {
            $headers[] = 'Bcc: ' . trim($bcc);
        }

        foreach ($extraHeaders as $h) {
            $headers[] = $h;
        }

        $validAttachments = array_filter($attachments, fn ($file) => $file instanceof UploadedFile && $file->isValid());

        if (empty($validAttachments)) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';

            return implode("\r\n", $headers) . "\r\n\r\n" . $body;
        }

        $boundary = '=_Part_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $parts = [];
        $parts[] = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$body}";

        foreach ($validAttachments as $file) {
            $filename = str_replace('"', '', $file->getClientOriginalName());
            $mime = $file->getMimeType() ?: 'application/octet-stream';
            $content = chunk_split(base64_encode((string) file_get_contents($file->getRealPath())));
            $parts[] = "--{$boundary}\r\n"
                . "Content-Type: {$mime}; name=\"{$filename}\"\r\n"
                . "Content-Disposition: attachment; filename=\"{$filename}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n{$content}";
        }

        $parts[] = "--{$boundary}--";

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
    }

    /**
     * @param  array<int, \Google\Service\Gmail\MessagePartHeader>  $headerObjects
     * @return array<string, string>
     */
    private function extractHeaders(array $headerObjects): array
    {
        $headers = [];
        foreach ($headerObjects as $header) {
            $headers[strtolower($header->getName())] = $header->getValue();
        }

        return $headers;
    }

    /**
     * @return array{plain: ?string, html: ?string}
     */
    private function extractBodies(?MessagePart $part): array
    {
        $result = ['plain' => null, 'html' => null];
        if (!$part) {
            return $result;
        }

        $mimeType = strtolower((string) $part->getMimeType());
        $data = $part->getBody()?->getData();

        if ($data !== null && $data !== '' && empty($part->getFilename())) {
            $decoded = $this->decodeBase64Url($data);
            if ($mimeType === 'text/plain' && $result['plain'] === null) {
                $result['plain'] = $decoded;
            } elseif ($mimeType === 'text/html' && $result['html'] === null) {
                $result['html'] = $decoded;
            }
        }

        foreach ($part->getParts() ?? [] as $subPart) {
            $subBodies = $this->extractBodies($subPart);
            if ($result['plain'] === null && $subBodies['plain'] !== null) {
                $result['plain'] = $subBodies['plain'];
            }
            if ($result['html'] === null && $subBodies['html'] !== null) {
                $result['html'] = $subBodies['html'];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{id: string, filename: string, mimeType: string, size: int}>
     */
    private function extractAttachments(?MessagePart $part): array
    {
        $attachments = [];
        if (!$part) {
            return $attachments;
        }

        $filename = $part->getFilename();
        $attachmentId = $part->getBody()?->getAttachmentId();
        if (!empty($filename) && !empty($attachmentId)) {
            $attachments[] = [
                'id' => $attachmentId,
                'filename' => $filename,
                'mimeType' => (string) $part->getMimeType(),
                'size' => (int) $part->getBody()?->getSize(),
            ];
        }

        foreach ($part->getParts() ?? [] as $subPart) {
            $attachments = array_merge($attachments, $this->extractAttachments($subPart));
        }

        return $attachments;
    }

    private function encodeBase64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function decodeBase64Url(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    private function sanitizeHtml(string $html): string
    {
        $cleaned = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $cleaned = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1=$2#$2', $cleaned) ?? $cleaned;

        return $cleaned;
    }

    private function htmlToText(string $html): string
    {
        $cleaned = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $cleaned = preg_replace('/<(br|p|div|li|tr|h[1-6])\b[^>]*>/i', "\n", $cleaned) ?? $cleaned;
        $cleaned = strip_tags($cleaned);
        $cleaned = html_entity_decode($cleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $cleaned) ?? $cleaned);
    }

    private function parseSenderName(string $from): string
    {
        if (preg_match('/^"?([^"<]+)"?\s*<[^>]+>$/', trim($from), $matches)) {
            return trim($matches[1]);
        }

        return trim($from);
    }

    private function formatDate(string $rawDate, bool $full = false): string
    {
        if (trim($rawDate) === '') {
            return '';
        }

        try {
            $dt = Carbon::parse($rawDate)->setTimezone(config('app.timezone'));
            if ($full) {
                return $dt->format('M j, Y, g:i A');
            }

            if ($dt->isToday()) {
                return $dt->format('g:i A');
            }

            if ($dt->isCurrentYear()) {
                return $dt->format('M j');
            }

            return $dt->format('M j, Y');
        } catch (\Throwable) {
            return $rawDate;
        }
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }
}
