# Gmail Workspace (Laravel + Gmail API + OpenRouter AI)

A full-featured, fast Gmail client built with **Laravel 13**, **MySQL**, **Laravel Socialite**, **Google API PHP Client (`google/apiclient`)**, and **OpenRouter AI (`deepseek/deepseek-chat`)**. Connect your Google account via OAuth 2.0 to browse folders, auto-sync new emails in the background, view AI-extracted keywords at a glance, read full conversation threads (Rich HTML & Plain Text), download attachments, compose new emails with CC/BCC/attachments, and send threaded replies.

---

## Features

- **Google OAuth 2.0 + Auto Token Refresh**
  - Uses `laravel/socialite` with offline access (`access_type=offline`, `prompt=consent`) to obtain a refresh token.
  - Encrypts `access_token` and `refresh_token` at rest in MySQL using Laravel's `encrypted` model cast.
  - Automatically refreshes expired access tokens via `GmailService::getValidToken()`.
- **High-Speed Inbox, Pagination & Background Auto-Sync**
  - Uses **Gmail Batch API (`createBatch()`)** to fetch up to 50 message metadata payloads in a single HTTP request.
  - **Background Auto-Sync (25s AJAX Polling):** `inbox.blade.php` polls `?partial=1&refresh=1` every 25 seconds via `fetch()` to update the message list without reloading the page (automatically pauses when composing, tab is hidden, or viewing Page 2+).
  - 45-second versioned file cache for instant navigation, automatically invalidated on any mutation or manual refresh.
  - Supports **Inbox**, **Starred**, **Sent**, **Drafts**, **All Mail**, **Spam**, and **Trash** folders.
  - Configurable **25 / 50 / 100** emails per page with **Previous Page** and **Load Next Page** navigation (via session page-token stack).
  - Full Gmail search syntax support (`from:`, `subject:`, `has:attachment`, etc.).
- **AI Keyword Extraction (OpenRouter / DeepSeek)**
  - Uses **OpenRouter Unified API** (`deepseek/deepseek-chat`) via `AiKeywordService` to extract **2 to 6 main keywords** tailored to each email's content (short emails get 2–3, normal get 4, detailed get 5–6).
  - Processes uncached emails progressively in batches of 8 via `POST /gmail/keywords` so the inbox renders instantly.
  - Caches extracted keywords for **30 days** per message ID (`gmail:kw:v3:<id>`).
- **Rich Email Viewer & Actions**
  - Full conversation **Thread View** (`users.threads.get`).
  - Toggle between sanitized **Rich HTML** (rendered inside a sandboxed `<iframe sandbox="allow-same-origin">`) and **Plain Text**.
  - View and download email attachments (`users.messages.attachments.get`).
  - **Star / Unstar**, **Mark Read / Unread**, **Archive**, **Move to Trash**, and **Restore**.
- **Compose, Reply, Reply All & Forward**
  - Compose new emails with **To**, **CC**, **BCC**, and up to **5 file attachments** (`multipart/mixed` RFC 2822 MIME).
  - Threaded **Reply** and **Reply All** with proper `In-Reply-To`, `References`, `Re:` subject prefix, and `threadId`.
  - **Forward** emails with an optional note.
- **Timezone Support**
  - Configured for Pakistan Standard Time (`Asia/Karachi`, UTC+5) via `APP_TIMEZONE`.

---

## Tech Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.3+, Laravel 13 (`laravel/framework ^13.0`) |
| Database | MySQL / MariaDB |
| Auth / OAuth | `laravel/socialite` (`^5.33`) |
| Gmail API | `google/apiclient` (`^2.20`) |
| AI Keywords | OpenRouter API (`deepseek/deepseek-chat`) |
| Frontend | Blade Templates + Tailwind CSS + Vanilla JS (`fetch` polling) |

---

## Prerequisites

- **PHP 8.3+** with `pdo_mysql`, `openssl`, `mbstring`, `curl`, `fileinfo` extensions
- **Composer**
- **MySQL / MariaDB**
- A **Google Cloud Console** project with the **Gmail API** enabled and an **OAuth 2.0 Web Application** client
- An **OpenRouter** API key (for AI email keyword extraction)

---

## Installation & Setup

### 1. Clone the repository
```bash
git clone https://github.com/<your-username>/gmail-test.git
cd gmail-test
```

### 2. Install PHP dependencies
```bash
composer install
```

### 3. Configure Environment
```bash
cp .env.example .env
php artisan key:generate
```

Update `.env` with your MySQL database, Google OAuth, and OpenRouter credentials:
```env
APP_URL=http://127.0.0.1:8000
APP_TIMEZONE=Asia/Karachi

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE="gmail- test"
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=file
CACHE_STORE=file

GOOGLE_CLIENT_ID=your-google-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/gmail/callback

OPENROUTER_API_KEY=your-openrouter-api-key
OPENROUTER_API_URL=https://openrouter.ai/api/v1/chat/completions
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_DEFAULT_MODEL=deepseek/deepseek-chat
```

### 4. Google Cloud Console Setup
1. Go to [Google Cloud Console](https://console.cloud.google.com/) and enable the **Gmail API**.
2. Configure the **OAuth Consent Screen** (External / Testing) and add your Gmail address under **Audience → Test users**.
3. Under **Clients**, create an **OAuth 2.0 Client ID (Web application)** and add Authorised Redirect URI:
   ```
   http://127.0.0.1:8000/gmail/callback
   ```
4. Copy the Client ID and Client Secret into `.env`.

### 5. Run Migrations & Optimize
```bash
php artisan migrate
php artisan optimize
```

### 6. Start the Development Server
```bash
php artisan serve --host=127.0.0.1 --port=8000
```
Open `http://127.0.0.1:8000` in your browser and click **Connect with Google**.

> **Note:** If you update `.env` later, run `php artisan optimize:clear` followed by `php artisan optimize`.

---

## Database Schema (`gmail_accounts`)

| Column | Type | Description |
| --- | --- | --- |
| `id` | `bigint` | Primary key |
| `user_id` | `foreignId` (nullable) | Optional user reference |
| `email` | `string` (unique) | Connected Gmail address |
| `access_token` | `text` | Encrypted OAuth access token |
| `refresh_token` | `text` (nullable) | Encrypted OAuth refresh token |
| `expires_at` | `timestamp` | Access token expiration time |
| `created_at` / `updated_at` | `timestamps` | Record timestamps |

---

## Routes

| Method | URI | Action | Description |
| --- | --- | --- | --- |
| `GET` | `/` | `GmailController@home` | Landing / workspace status page |
| `GET` | `/gmail/connect` | `GmailController@redirect` | Redirect to Google OAuth consent |
| `GET` | `/gmail/callback` | `GmailController@callback` | Handle OAuth callback & store tokens |
| `GET` | `/gmail/inbox` | `GmailController@inbox` | Folder list, search, pagination & partial sync |
| `POST` | `/gmail/keywords` | `GmailController@keywords` | Batch AI keyword extraction (OpenRouter) |
| `GET` | `/gmail/message/{id}` | `GmailController@show` | Full thread & message detail |
| `GET` | `/gmail/message/{id}/attachment/{attachmentId}` | `GmailController@attachment` | Download attachment |
| `POST` | `/gmail/compose` | `GmailController@compose` | Send new email (To/CC/BCC/files) |
| `POST` | `/gmail/message/{id}/reply` | `GmailController@reply` | Send threaded Reply / Reply All |
| `POST` | `/gmail/message/{id}/forward` | `GmailController@forward` | Forward email |
| `POST` | `/gmail/message/{id}/star` | `GmailController@toggleStar` | Star or unstar message |
| `POST` | `/gmail/message/{id}/read` | `GmailController@toggleRead` | Mark read or unread |
| `POST` | `/gmail/message/{id}/archive` | `GmailController@archive` | Archive message |
| `POST` | `/gmail/message/{id}/trash` | `GmailController@trash` | Move message to Trash |
| `POST` | `/gmail/message/{id}/untrash` | `GmailController@untrash` | Restore message from Trash |
| `POST` | `/gmail/disconnect` | `GmailController@disconnect` | Disconnect Gmail account |

---

## Project Structure

```text
app/
├── Http/Controllers/GmailController.php   # OAuth, inbox, keywords, thread view, compose, reply, actions
├── Models/GmailAccount.php                # Encrypted token casts
└── Services/
    ├── AiKeywordService.php               # OpenRouter batch 2-6 keyword extraction + 30d cache
    └── GmailService.php                   # Batch API, token refresh, MIME builder, sanitizer
config/
└── services.php                           # Google OAuth & OpenRouter configuration
database/
└── migrations/2026_11_25_000001_create_gmail_accounts_table.php
resources/views/
├── layouts/app.blade.php                  # App shell, sidebar, search, compose modal
└── gmail/
    ├── home.blade.php                     # Connect / status landing page
    ├── inbox.blade.php                    # Inbox toolbar, 25s AJAX auto-sync & AI keyword loader
    ├── partials/message-list.blade.php    # Message rows, AI keyword pills, Prev/Next pagination
    └── show.blade.php                     # Thread conversation & reply/forward
routes/
└── web.php                                # Application routes
```

---

## Security Notes

- **Never commit `.env`** — `.env` is excluded by `.gitignore`. Only `.env.example` is tracked.
- OAuth `access_token` and `refresh_token` are encrypted in the database using Laravel's `APP_KEY`.
- HTML email bodies are stripped of `<script>` tags and inline `on*` event handlers, and rendered inside a sandboxed `<iframe>`.
