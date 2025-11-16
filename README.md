# Rate Automation System

A Laravel-based application that automatically extracts shipment rate cards from Gmail emails (easternrate@gmail.com). The system processes both Excel attachments and inline HTML tables to extract rate information for FCL Import/Export and LCL Import/Export shipments.

## Features

- **Gmail API Integration**: Monitors Gmail inbox for rate card emails
- **Dual Extraction Methods**:
  - Excel file parsing (.xlsx, .xls, .csv)
  - HTML table extraction from email body
- **Rate Type Support**: FCL_IMPORT, FCL_EXPORT, LCL_IMPORT, LCL_EXPORT
- **Background Processing**: Queue-based email processing
- **Automated Scheduling**: Fetches emails every 10 minutes
- **Comprehensive Logging**: Tracks all processing attempts and errors

## Requirements

- PHP 8.2 or higher
- MySQL 5.7 or higher
- Composer
- Google Cloud Console account with Gmail API enabled

## Installation

### 1. Clone and Install Dependencies

```bash
cd rate_automation
composer install
```

### 2. Configure Environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and configure your database:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rate_automation
DB_USERNAME=root
DB_PASSWORD=your_password
```

### 3. Set Up Gmail API

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing one
3. Enable Gmail API
4. Create OAuth 2.0 credentials:
   - Application type: Web application
   - Authorized redirect URIs: `http://localhost:8000/auth/google/callback`
5. Download credentials and add to `.env`:

```env
GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret
GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback
GMAIL_ADDRESS=easternrate@gmail.com
```

### 4. Run Migrations

```bash
php artisan migrate
```

### 5. Authenticate with Gmail

Start the development server:

```bash
php artisan serve
```

Visit `http://localhost:8000/auth/google` in your browser to authenticate with Gmail. This will store the access token for future use.

## Usage

### Manual Email Fetching

Fetch and process emails manually:

```bash
php artisan emails:fetch
```

Fetch with custom limit:

```bash
php artisan emails:fetch --max=20
```

### Queue Worker

Start the queue worker to process emails in the background:

```bash
php artisan queue:work
```

### Task Scheduler

The system automatically fetches emails every 10 minutes. To enable the scheduler, add this to your crontab:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Or run the scheduler manually for development:

```bash
php artisan schedule:work
```

## Architecture

### Database Tables

- **emails**: Stores email metadata and content
- **rate_cards**: Extracted rate card information
- **attachments**: Downloaded Excel files
- **processing_logs**: Extraction attempts and errors

### Services

- **GmailService**: Handles Gmail API authentication and email fetching
- **ExcelExtractorService**: Parses Excel attachments for rate data
- **HtmlExtractorService**: Extracts tables from HTML email body

### Jobs

- **ProcessEmailJob**: Background job for processing individual emails

### Commands

- **emails:fetch**: Fetch unread emails from Gmail and queue for processing

## Rate Card Data Structure

The system extracts the following rate card information:

- Carrier/Shipping Line
- Origin Port (POL)
- Destination Port (POD)
- Rate/Freight Cost
- Currency
- Container Type (20ft, 40ft, 40HC)
- Service Type (FCL_IMPORT, FCL_EXPORT, LCL_IMPORT, LCL_EXPORT)
- Effective Date
- Expiry Date
- Remarks/Notes
- Additional Charges

## Email Processing Flow

1. Scheduler triggers `emails:fetch` command every 10 minutes
2. Command fetches unread emails via Gmail API
3. Each email is queued as `ProcessEmailJob`
4. Job processes email:
   - Downloads and processes Excel attachments
   - Extracts HTML tables from email body
   - Stores rate cards in database
   - Logs all operations
5. Email marked as processed

## Troubleshooting

### Token Refresh Issues

If you get "Refresh token not available" error:
1. Delete `storage/app/gmail_token.json`
2. Re-authenticate at `http://localhost:8000/auth/google`

### Database Connection Failed

Ensure MySQL is running and credentials in `.env` are correct:

```bash
php artisan config:clear
php artisan cache:clear
```

### Queue Jobs Not Processing

Check queue connection in `.env`:

```env
QUEUE_CONNECTION=database
```

Run migrations to create jobs table:

```bash
php artisan queue:table
php artisan migrate
```

## Development

### Running Tests

```bash
php artisan test
```

### Clearing Cache

```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Viewing Logs

Processing logs are stored in:
- `storage/logs/laravel.log` (Application logs)
- `processing_logs` table (Database logs)

## Project Structure

```
app/
├── Console/Commands/
│   └── FetchEmailsCommand.php
├── Http/Controllers/
│   └── GoogleAuthController.php
├── Jobs/
│   └── ProcessEmailJob.php
├── Models/
│   ├── Email.php
│   ├── RateCard.php
│   ├── Attachment.php
│   └── ProcessingLog.php
└── Services/
    ├── GmailService.php
    ├── ExcelExtractorService.php
    └── HtmlExtractorService.php
```

## Contributing

This is a private project. Please contact the project maintainer for contribution guidelines.

## License

Proprietary - All rights reserved
