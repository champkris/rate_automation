<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use App\Models\Email;
use App\Models\Attachment;
use App\Models\ProcessingLog;
use Illuminate\Support\Facades\Storage;
use Exception;

class GmailService
{
    private GoogleClient $client;
    private Gmail $service;

    public function __construct()
    {
        $this->client = new GoogleClient();
        $this->client->setApplicationName('Rate Automation');
        $this->client->setScopes([
            Gmail::GMAIL_READONLY,
        ]);
        $this->client->setAuthConfig([
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uris' => [config('services.google.redirect_uri')],
        ]);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');

        // Load token if exists
        $this->loadToken();

        $this->service = new Gmail($this->client);
    }

    private function loadToken(): void
    {
        $tokenPath = storage_path('app/gmail_token.json');

        if (file_exists($tokenPath)) {
            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $this->client->setAccessToken($accessToken);

            // Refresh token if expired
            if ($this->client->isAccessTokenExpired()) {
                if ($this->client->getRefreshToken()) {
                    $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                    $this->saveToken($this->client->getAccessToken());
                } else {
                    throw new Exception('Refresh token not available. Re-authentication required.');
                }
            }
        }
    }

    public function saveToken(array $token): void
    {
        $tokenPath = storage_path('app/gmail_token.json');
        file_put_contents($tokenPath, json_encode($token));
    }

    public function getAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    public function authenticate(string $authCode): void
    {
        $accessToken = $this->client->fetchAccessTokenWithAuthCode($authCode);
        $this->saveToken($accessToken);
    }

    public function fetchUnreadEmails(int $maxResults = 10): array
    {
        try {
            $userId = 'me';
            $query = 'is:unread'; // You can customize this query to filter rate card emails

            $messages = $this->service->users_messages->listUsersMessages($userId, [
                'maxResults' => $maxResults,
                'q' => $query,
            ]);

            $emails = [];
            foreach ($messages->getMessages() as $message) {
                $email = $this->fetchEmailDetails($message->getId());
                if ($email) {
                    $emails[] = $email;
                }
            }

            ProcessingLog::logSuccess(
                'email_fetch',
                "Fetched {count($emails)} unread emails",
                null,
                ['count' => count($emails)]
            );

            return $emails;
        } catch (Exception $e) {
            ProcessingLog::logFailure(
                'email_fetch',
                'Failed to fetch emails from Gmail',
                $e
            );
            throw $e;
        }
    }

    public function fetchEmailDetails(string $messageId): ?Email
    {
        try {
            $userId = 'me';
            $message = $this->service->users_messages->get($userId, $messageId, ['format' => 'full']);

            // Extract email data
            $headers = $this->parseHeaders($message->getPayload()->getHeaders());

            $bodyHtml = null;
            $bodyText = null;
            $this->extractBody($message->getPayload(), $bodyHtml, $bodyText);

            // Check if email already exists
            $email = Email::where('message_id', $message->getId())->first();
            if ($email) {
                return $email;
            }

            // Create email record
            $email = Email::create([
                'message_id' => $message->getId(),
                'subject' => $headers['subject'] ?? null,
                'from' => $headers['from'] ?? null,
                'to' => $headers['to'] ?? null,
                'cc' => $headers['cc'] ?? null,
                'body_html' => $bodyHtml,
                'body_text' => $bodyText,
                'has_attachments' => false,
                'received_at' => isset($headers['date']) ? date('Y-m-d H:i:s', strtotime($headers['date'])) : now(),
                'status' => 'pending',
            ]);

            // Process attachments
            $this->processAttachments($message, $email);

            return $email;
        } catch (Exception $e) {
            ProcessingLog::logFailure(
                'email_process',
                "Failed to fetch email details for message ID: {$messageId}",
                $e
            );
            return null;
        }
    }

    private function parseHeaders(array $headers): array
    {
        $parsed = [];
        foreach ($headers as $header) {
            $parsed[strtolower($header->getName())] = $header->getValue();
        }
        return $parsed;
    }

    private function extractBody($part, ?string &$htmlBody, ?string &$textBody): void
    {
        if ($part->getMimeType() === 'text/html') {
            $htmlBody = base64_decode(strtr($part->getBody()->getData(), '-_', '+/'));
        } elseif ($part->getMimeType() === 'text/plain') {
            $textBody = base64_decode(strtr($part->getBody()->getData(), '-_', '+/'));
        }

        if ($part->getParts()) {
            foreach ($part->getParts() as $subPart) {
                $this->extractBody($subPart, $htmlBody, $textBody);
            }
        }
    }

    private function processAttachments(Message $message, Email $email): void
    {
        $userId = 'me';
        $parts = $message->getPayload()->getParts();

        if (!$parts) {
            return;
        }

        foreach ($parts as $part) {
            $filename = $part->getFilename();

            if (!empty($filename)) {
                $attachmentId = $part->getBody()->getAttachmentId();

                if ($attachmentId) {
                    try {
                        $attachment = $this->service->users_messages_attachments->get(
                            $userId,
                            $message->getId(),
                            $attachmentId
                        );

                        $data = base64_decode(strtr($attachment->getData(), '-_', '+/'));

                        // Save to storage
                        $directory = 'attachments/' . $email->id;
                        Storage::makeDirectory($directory);
                        $filePath = $directory . '/' . $filename;
                        Storage::put($filePath, $data);

                        // Create attachment record
                        Attachment::create([
                            'email_id' => $email->id,
                            'filename' => $filename,
                            'mime_type' => $part->getMimeType(),
                            'file_path' => $filePath,
                            'file_size' => strlen($data),
                            'extraction_status' => 'pending',
                        ]);

                        $email->update(['has_attachments' => true]);
                    } catch (Exception $e) {
                        ProcessingLog::logFailure(
                            'email_process',
                            "Failed to download attachment: {$filename}",
                            $e,
                            $email->id
                        );
                    }
                }
            }
        }
    }

    public function markAsRead(string $messageId): void
    {
        try {
            $userId = 'me';
            $mods = new \Google\Service\Gmail\ModifyMessageRequest();
            $mods->setRemoveLabelIds(['UNREAD']);
            $this->service->users_messages->modify($userId, $messageId, $mods);
        } catch (Exception $e) {
            ProcessingLog::logFailure(
                'email_process',
                "Failed to mark email as read: {$messageId}",
                $e
            );
        }
    }
}
