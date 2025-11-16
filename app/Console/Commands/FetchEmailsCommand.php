<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEmailJob;
use App\Services\GmailService;
use Illuminate\Console\Command;
use Exception;

class FetchEmailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'emails:fetch {--max=10 : Maximum number of emails to fetch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch unread emails from Gmail and queue them for processing';

    /**
     * Execute the console command.
     */
    public function handle(GmailService $gmailService): int
    {
        try {
            $this->info('Fetching emails from Gmail...');

            $maxResults = (int) $this->option('max');
            $emails = $gmailService->fetchUnreadEmails($maxResults);

            if (empty($emails)) {
                $this->info('No unread emails found.');
                return self::SUCCESS;
            }

            $this->info("Found " . count($emails) . " unread email(s).");

            // Queue each email for processing
            $bar = $this->output->createProgressBar(count($emails));
            $bar->start();

            foreach ($emails as $email) {
                ProcessEmailJob::dispatch($email);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            $this->info("Queued " . count($emails) . " email(s) for processing.");

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error("Failed to fetch emails: " . $e->getMessage());
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }
    }
}
