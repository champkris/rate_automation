# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Rate Automation is a Laravel-based application that automatically extracts shipment rate cards from Gmail emails. The system monitors the inbox of easternrate@gmail.com for emails containing rate card information, extracts the data from either Excel attachments or inline HTML tables, and stores it in a MySQL database.

## Technology Stack

- **Framework**: Laravel (PHP)
- **Database**: MySQL
- **Email Source**: Gmail (easternrate@gmail.com)
- **Data Formats**: Excel attachments, HTML tables in email body

## Development Setup

### Initial Setup
```bash
# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Configure database in .env file
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=rate_automation
# DB_USERNAME=your_username
# DB_PASSWORD=your_password

# Run migrations
php artisan migrate

# Start development server
php artisan serve
```

### Common Development Commands

```bash
# Run migrations
php artisan migrate

# Rollback migrations
php artisan migrate:rollback

# Create new migration
php artisan make:migration create_table_name

# Create new model with migration
php artisan make:model ModelName -m

# Create controller
php artisan make:controller ControllerName

# Create job for background processing
php artisan make:job JobName

# Create command
php artisan make:command CommandName

# Run artisan command
php artisan command:name

# Clear application cache
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Run queue worker (for processing emails in background)
php artisan queue:work

# Run scheduler (for periodic email checks)
php artisan schedule:work
```

### Testing

```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test --filter TestClassName

# Run tests with coverage
php artisan test --coverage
```

## Architecture

### Gmail Integration

The application connects to Gmail using one of these approaches:
- **Gmail API**: OAuth2 authentication with Google API Client
- **IMAP**: Direct IMAP connection to Gmail servers

Key considerations:
- Store Gmail API credentials in `.env` file
- Implement OAuth2 flow for initial authentication
- Store refresh tokens in database for persistent access
- Monitor both direct emails and CC'd emails

### Rate Card Extraction Pipeline

1. **Email Fetching**: Scheduled job or queue worker periodically checks Gmail inbox
2. **Email Processing**:
   - Identify emails containing rate cards (by subject, sender, or keywords)
   - Route to appropriate extraction handler
3. **Data Extraction**:
   - **Excel Handler**: Download attachment, parse using PhpSpreadsheet
   - **HTML Handler**: Extract table from email body using DOMDocument/DOMXPath
4. **Data Storage**: Normalize and store rate card data in MySQL database
5. **Notification**: Optional notifications on successful/failed extractions

### Database Structure

Key tables (to be created):
- `emails`: Track processed emails (message_id, subject, from, received_at, processed_at)
- `rate_cards`: Store extracted rate card information
- `attachments`: Track downloaded Excel files
- `processing_logs`: Log extraction attempts and errors

### Key Components

- **Commands**: Artisan commands to manually trigger email fetching
- **Jobs**: Queue jobs for background email processing
- **Services**:
  - `GmailService`: Handle Gmail API connections
  - `ExcelExtractorService`: Parse Excel attachments
  - `HtmlExtractorService`: Extract data from HTML tables
  - `RateCardService`: Business logic for rate card processing
- **Models**: Eloquent models for database tables
- **Scheduled Tasks**: Cron jobs defined in `app/Console/Kernel.php`

## Gmail Configuration

### Required Google API Setup

1. Enable Gmail API in Google Cloud Console
2. Create OAuth 2.0 credentials
3. Add authorized redirect URIs
4. Download credentials JSON
5. Configure in `.env`:
```
GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret
GOOGLE_REDIRECT_URI=your_redirect_uri
GMAIL_ADDRESS=easternrate@gmail.com
```

### Email Filtering

The system should identify rate card emails by:
- Specific sender addresses
- Subject line patterns
- Presence of Excel attachments with specific naming patterns
- HTML tables with rate card indicators

## Data Extraction Patterns

### Excel Attachments
- Use PhpSpreadsheet library
- Support .xlsx, .xls, .csv formats
- Identify header rows dynamically
- Map columns to database fields
- Handle merged cells and formatting

### HTML Tables
- Parse email HTML body
- Use DOMDocument to extract `<table>` elements
- Identify table structure (headers, data rows)
- Handle nested tables and formatting
- Clean up inline styles and attributes

## Background Processing

Email processing should run in background:
- Use Laravel queues (database, Redis, or SQS)
- Schedule periodic checks (every 5-15 minutes)
- Implement retry logic for failed extractions
- Log all processing attempts

## Error Handling

- Log all errors to `storage/logs/laravel.log`
- Store failed extraction attempts in database
- Send notifications for critical failures
- Implement manual retry mechanism for failed emails
