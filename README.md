# Gmail Workspace (Laravel + Gmail API)

A full-featured, fast Gmail client built with **Laravel 13**, **MySQL**, **Laravel Socialite**, and the **Google API PHP Client (`google/apiclient`)**. Connect your Google account via OAuth 2.0 to browse folders, search emails, read full conversation threads (Rich HTML & Plain Text), download attachments, compose new emails with CC/BCC/attachments, and send threaded replies.

---

## Features

- **Google OAuth 2.0 + Auto Token Refresh**
  - Uses `laravel/socialite` with offline access (`access_type=offline`, `prompt=consent`) to obtain a refresh token.
  - Encrypts `access_token` and `refresh_token` at rest in MySQL using Laravel's `encrypted` model cast.
  - Automatically refreshes expired access tokens via `GmailService::getValidToken()`.
- **High-Speed Inbox & Folders**
  - Uses **Gmail Batch API (`createBatch()`)** to fetch up to 50 message metadata payloads in a single HTTP request.
  - 45-second versioned file cache for instant navigation, automatically invalidated on any mutation or manual refresh.
  - Supports **Inbox**, **Starred**, **Sent**, **Drafts**, **All Mail**, **Spam**, and **Trash** folders.
  - Configurable pagination (`25`, `50`, or `100` messages per page) + `Next Page` token pagination.
  - Full Gmail search syntax support (`from:`, `subject:`, `has:attachment`, etc.).
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
| Frontend | Blade Templates + Tailwind CSS + Vanilla JS |

---

## Prerequisites

- **PHP 8.3+** with `pdo_mysql`, `openssl`, `mbstring`, `curl`, `fileinfo` extensions
- **Composer**
- **MySQL / MariaDB**
- A **Google Cloud Console** project with the **Gmail API** enabled and an **OAuth 2.0 Web Application** client

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

Update `.env` with your MySQL database and Google OAuth credentials:
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
| `GET` | `/gmail/inbox` | `GmailController@inbox` | Folder list, search & pagination |
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
├── Http/Controllers/GmailController.php   # OAuth, inbox, thread view, compose, reply, actions
├── Models/GmailAccount.php                # Encrypted token casts
└── Services/GmailService.php              # Batch API, token refresh, MIME builder, sanitizer
config/
└── services.php                           # Google OAuth configuration
database/
└── migrations/2026_11_25_000001_create_gmail_accounts_table.php
resources/views/
├── layouts/app.blade.php                  # App shell, sidebar, search, compose modal
└── gmail/
    ├── home.blade.php                     # Connect / status landing page
    ├── inbox.blade.php                    # Message list & quick actions
    └── show.blade.php                     # Thread conversation & reply/forward
routes/
└── web.php                                # Application routes
```

---

## Security Notes

- **Never commit `.env`** — `.env` is excluded by `.gitignore`. Only `.env.example` is tracked.
- OAuth `access_token` and `refresh_token` are encrypted in the database using Laravel's `APP_KEY`.
- HTML email bodies are stripped of `<script>` tags and inline `on*` event handlers, and rendered inside a sandboxed `<iframe>`.
