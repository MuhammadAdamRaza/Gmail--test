<?php

namespace App\Http\Controllers;

use App\Models\GmailAccount;
use App\Services\GmailService;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;
use Throwable;

class GmailController extends Controller
{
    /**
     * Landing page so opening the app does not immediately force Google OAuth.
     */
    public function home(): View
    {
        $account = $this->resolveAccount();

        return view('gmail.home', compact('account'));
    }

    /**
     * Redirect the user to Google's OAuth consent screen.
     */
    public function redirect(): RedirectResponse
    {
        /** @var \Laravel\Socialite\Two\AbstractProvider $provider */
        $provider = Socialite::driver('google');

        return $provider
            ->scopes([
                'openid',
                'email',
                'https://www.googleapis.com/auth/gmail.modify',
                'https://www.googleapis.com/auth/gmail.send',
            ])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
            ])
            ->redirect();
    }

    /**
     * Handle the Google OAuth callback and persist tokens.
     */
    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::error('Google OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect()->route('gmail.home')
                ->with('error', 'Google authentication failed: ' . $e->getMessage());
        }

        $email = $googleUser->getEmail();
        if (empty($email)) {
            return redirect()->route('gmail.home')
                ->with('error', 'Could not retrieve email address from Google.');
        }

        $existing = GmailAccount::where('email', $email)->first();

        $account = GmailAccount::updateOrCreate(
            ['email' => $email],
            [
                'access_token' => $googleUser->token,
                'refresh_token' => $googleUser->refreshToken ?: $existing?->refresh_token,
                'expires_at' => now()->addSeconds((int) ($googleUser->expiresIn ?? 3600)),
            ]
        );

        session(['gmail_account_id' => $account->id]);

        return redirect()->route('gmail.inbox')
            ->with('success', 'Connected Gmail account: ' . $account->email);
    }

    /**
     * Display inbox / folder messages with search and pagination.
     */
    public function inbox(Request $request, GmailService $gmailService): View|RedirectResponse
    {
        $account = $this->resolveAccount();
        if (!$account) {
            return redirect()->route('gmail.home')
                ->with('error', 'Please connect your Gmail account first.');
        }

        $label = strtoupper((string) $request->query('label', 'INBOX'));
        $allowedLabels = ['INBOX', 'SENT', 'STARRED', 'DRAFT', 'SPAM', 'TRASH', 'ALL'];
        if (!in_array($label, $allowedLabels, true)) {
            $label = 'INBOX';
        }

        $perPage = (int) $request->query('perPage', 25);
        if (!in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }

        $query = $request->query('q');
        $pageToken = $request->query('pageToken');
        $forceRefresh = $request->boolean('refresh');

        try {
            $result = $gmailService->listMessages(
                $account,
                $label,
                is_string($query) ? $query : null,
                is_string($pageToken) ? $pageToken : null,
                $perPage,
                $forceRefresh
            );
        } catch (GoogleServiceException $e) {
            return $this->handleGoogleException($e);
        } catch (RuntimeException $e) {
            return redirect()->route('gmail.home')
                ->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Failed to load Gmail inbox', ['error' => $e->getMessage()]);

            return redirect()->route('gmail.home')
                ->with('error', 'Failed to load inbox: ' . $e->getMessage());
        }

        return view('gmail.inbox', [
            'account' => $account,
            'messages' => $result['messages'],
            'nextPageToken' => $result['nextPageToken'],
            'resultSizeEstimate' => $result['resultSizeEstimate'],
            'activeLabel' => $label,
            'perPage' => $perPage,
            'searchQuery' => is_string($query) ? $query : '',
        ]);
    }

    /**
     * Display a single Gmail message/thread, attachments, and reply/forward forms.
     */
    public function show(string $id, GmailService $gmailService): View|RedirectResponse
    {
        $account = $this->resolveAccount();
        if (!$account) {
            return redirect()->route('gmail.home')
                ->with('error', 'Please connect your Gmail account first.');
        }

        try {
            $message = $gmailService->getMessageDetail($account, $id);
        } catch (GoogleServiceException $e) {
            return $this->handleGoogleException($e);
        } catch (RuntimeException $e) {
            return redirect()->route('gmail.home')
                ->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Failed to load Gmail message', ['id' => $id, 'error' => $e->getMessage()]);

            return redirect()->route('gmail.inbox')
                ->with('error', 'Failed to load message: ' . $e->getMessage());
        }

        return view('gmail.show', compact('account', 'message'));
    }

    /**
     * Download an email attachment.
     */
    public function attachment(Request $request, string $id, string $attachmentId, GmailService $gmailService): Response|RedirectResponse
    {
        $account = $this->resolveAccount();
        if (!$account) {
            return redirect()->route('gmail.home')
                ->with('error', 'Please connect your Gmail account first.');
        }

        $filename = basename((string) $request->query('filename', 'attachment'));
        $mimeType = (string) $request->query('mime', 'application/octet-stream');

        try {
            $file = $gmailService->getAttachment($account, $id, $attachmentId, $filename, $mimeType);

            return response($file['data'], 200, [
                'Content-Type' => $file['mimeType'],
                'Content-Disposition' => 'attachment; filename="' . addslashes($file['filename']) . '"',
            ]);
        } catch (GoogleServiceException $e) {
            return $this->handleGoogleException($e, route('gmail.show', $id));
        } catch (Throwable $e) {
            Log::error('Failed to download attachment', ['id' => $id, 'error' => $e->getMessage()]);

            return redirect()->route('gmail.show', $id)
                ->with('error', 'Failed to download attachment.');
        }
    }

    /**
     * Send a new email from the compose modal (supports CC, BCC, and attachments).
     */
    public function compose(Request $request, GmailService $gmailService): RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'string', 'max:500'],
            'cc' => ['nullable', 'string', 'max:500'],
            'bcc' => ['nullable', 'string', 'max:500'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $account = $this->resolveAccount();
        if (!$account) {
            return redirect()->route('gmail.home')
                ->with('error', 'Please connect your Gmail account first.');
        }

        try {
            $gmailService->sendNewMessage(
                $account,
                $validated['to'],
                $validated['subject'],
                $validated['body'],
                $validated['cc'] ?? null,
                $validated['bcc'] ?? null,
                $request->file('attachments', [])
            );
        } catch (GoogleServiceException $e) {
            return $this->handleGoogleException($e, route('gmail.inbox'));
        } catch (RuntimeException $e) {
            return redirect()->route('gmail.home')
                ->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Failed to send new email', ['error' => $e->getMessage()]);

            return redirect()->route('gmail.inbox')
                ->withInput()
                ->with('error', 'Failed to send email: ' . $e->getMessage());
        }

        return redirect()->route('gmail.inbox', ['label' => 'SENT'])
            ->with('success', 'Email sent to ' . $validated['to'] . '.');
    }

    /**
     * Send a reply (or Reply All) to the specified Gmail message.
     */
    public function reply(Request $request, string $id, GmailService $gmailService): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'reply_all' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $account = $this->resolveAccount();
        if (!$account) {
            return redirect()->route('gmail.home')
                ->with('error', 'Please connect your Gmail account first.');
        }

        try {
            $gmailService->sendReply(
                $account,
                $id,
                $validated['body'],
                $request->boolean('reply_all'),
                $request->file('attachments', [])
            );
        } catch (GoogleServiceException $e) {
            return $this->handleGoogleException($e, route('gmail.show', $id));
        } catch (RuntimeException $e) {
            return redirect()->route('gmail.home')
                ->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Failed to send reply', ['id' => $id, 'error' => $e->getMessage()]);

            return redirect()->route('gmail.show', $id)
                ->withInput()
                ->with('error', 'Failed to send reply: ' . $e->getMessage());
        }

        return redirect()->route('gmail.show', $id)
            ->with('success', 'Reply sent successfully.');
    }

    /**
     * Forward an existing message.
     */
    public function forward(Request $request, string $id, GmailService $gmailService): RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $account = $this->resolveAccount();
        if (!$account) {
            return redirect()->route('gmail.home')
                ->with('error', 'Please connect your Gmail account first.');
        }

        try {
            $gmailService->forwardMessage($account, $id, $validated['to'], (string) ($validated['note'] ?? ''));
        } catch (GoogleServiceException $e) {
            return $this->handleGoogleException($e, route('gmail.show', $id));
        } catch (Throwable $e) {
            Log::error('Failed to forward message', ['id' => $id, 'error' => $e->getMessage()]);

            return redirect()->route('gmail.show', $id)
                ->with('error', 'Failed to forward email: ' . $e->getMessage());
        }

        return redirect()->route('gmail.show', $id)
            ->with('success', 'Email forwarded to ' . $validated['to'] . '.');
    }

    /**
     * Star or unstar a message.
     */
    public function toggleStar(Request $request, string $id, GmailService $gmailService): RedirectResponse
    {
        $account = $this->resolveAccount();
        if (!$account) {
            return redirect()->route('gmail.home');
        }

        try {
            $gmailService->toggleStar($account, $id, $request->boolean('star'));
        } catch (GoogleServiceException $e) {
            return $this->handleGoogleException($e, back()->getTargetUrl());
        } catch (Throwable $e) {
            return back()->with('error', 'Could not update star: ' . $e->getMessage());
        }

        return back()->with('success', $request->boolean('star') ? 'Added star.' : 'Removed star.');
    }

    /**
     * Mark a message as read or unread.
     */
    public function toggleRead(Request $request, string $id, GmailService $gmailService): RedirectResponse
    {
        $account = $this->resolveAccount();
        if (!$account) {
            return redirect()->route('gmail.home');
        }

        try {
            $gmailService->toggleRead($account, $id, $request->boolean('read'));
        } catch (GoogleServiceException $e) {
            return $this->handleGoogleException($e, back()->getTargetUrl());
        } catch (Throwable $e) {
            return back()->with('error', 'Could not update read status: ' . $e->getMessage());
        }

        return back()->with('success', $request->boolean('read') ? 'Marked as read.' : 'Marked as unread.');
    }

    /**
     * Archive a message.
     */
    public function archive(string $id, GmailService $gmailService): RedirectResponse
    {
        $account = $this->resolveAccount();
        if (!$account) {
            return redirect()->route('gmail.home');
        }

        try {
            $gmailService->archiveMessage($account, $id);
        } catch (GoogleServiceException $e) {
            return $this->handleGoogleException($e, route('gmail.inbox'));
        } catch (Throwable $e) {
            return back()->with('error', 'Could not archive message: ' . $e->getMessage());
        }

        return redirect()->route('gmail.inbox')->with('success', 'Message archived.');
    }

    /**
     * Move a message to Trash.
     */
    public function trash(string $id, GmailService $gmailService): RedirectResponse
    {
        $account = $this->resolveAccount();
        if (!$account) {
            return redirect()->route('gmail.home');
        }

        try {
            $gmailService->trashMessage($account, $id);
        } catch (GoogleServiceException $e) {
            return $this->handleGoogleException($e, route('gmail.inbox'));
        } catch (Throwable $e) {
            return back()->with('error', 'Could not move to Trash: ' . $e->getMessage());
        }

        return redirect()->route('gmail.inbox')->with('success', 'Message moved to Trash.');
    }

    /**
     * Restore a message from Trash.
     */
    public function untrash(string $id, GmailService $gmailService): RedirectResponse
    {
        $account = $this->resolveAccount();
        if (!$account) {
            return redirect()->route('gmail.home');
        }

        try {
            $gmailService->untrashMessage($account, $id);
        } catch (GoogleServiceException $e) {
            return $this->handleGoogleException($e, route('gmail.inbox', ['label' => 'TRASH']));
        } catch (Throwable $e) {
            return back()->with('error', 'Could not restore message: ' . $e->getMessage());
        }

        return redirect()->route('gmail.inbox', ['label' => 'TRASH'])->with('success', 'Message restored from Trash.');
    }

    /**
     * Disconnect the currently connected Gmail account.
     */
    public function disconnect(): RedirectResponse
    {
        $account = $this->resolveAccount();
        if ($account) {
            $account->delete();
        }

        session()->forget('gmail_account_id');

        return redirect()->route('gmail.home')
            ->with('success', 'Gmail account disconnected.');
    }

    private function resolveAccount(): ?GmailAccount
    {
        $accountId = session('gmail_account_id');
        if ($accountId) {
            $account = GmailAccount::find($accountId);
            if ($account) {
                return $account;
            }
        }

        return GmailAccount::latest('updated_at')->first();
    }

    private function handleGoogleException(GoogleServiceException $e, ?string $fallbackUrl = null): RedirectResponse
    {
        Log::warning('Google API exception', ['code' => $e->getCode(), 'message' => $e->getMessage()]);

        $code = $e->getCode();
        if (in_array($code, [401, 403], true) || str_contains(strtolower($e->getMessage()), 'invalid_grant')) {
            return redirect()->route('gmail.home')
                ->with('error', 'Gmail authorization expired, revoked, or needs updated permissions. Please reconnect.');
        }

        if ($fallbackUrl) {
            return redirect($fallbackUrl)
                ->with('error', 'Google API error: ' . $e->getMessage());
        }

        return redirect()->route('gmail.home')
            ->with('error', 'Google API error: ' . $e->getMessage());
    }
}
