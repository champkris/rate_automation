<?php

namespace App\Jobs;

use App\Models\Email;
use App\Models\ProcessingLog;
use App\Services\ExcelExtractorService;
use App\Services\HtmlExtractorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Exception;

class ProcessEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Email $email
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(
        ExcelExtractorService $excelExtractor,
        HtmlExtractorService $htmlExtractor
    ): void {
        try {
            $this->email->markAsProcessing();

            ProcessingLog::create([
                'email_id' => $this->email->id,
                'type' => 'email_process',
                'status' => 'started',
                'message' => 'Started processing email',
            ]);

            $totalExtracted = 0;

            // Process Excel attachments
            if ($this->email->has_attachments) {
                foreach ($this->email->attachments as $attachment) {
                    if ($attachment->isExcel() && $attachment->extraction_status === 'pending') {
                        try {
                            $rateCards = $excelExtractor->extract($attachment);
                            $totalExtracted += count($rateCards);
                        } catch (Exception $e) {
                            ProcessingLog::logFailure(
                                'excel_extract',
                                "Failed to process attachment: {$attachment->filename}",
                                $e,
                                $this->email->id,
                                ['attachment_id' => $attachment->id]
                            );
                        }
                    }
                }
            }

            // Process HTML tables if no attachments or as fallback
            if ($this->email->body_html && !empty($this->email->body_html)) {
                try {
                    $rateCards = $htmlExtractor->extract($this->email);
                    $totalExtracted += count($rateCards);
                } catch (Exception $e) {
                    ProcessingLog::logFailure(
                        'html_extract',
                        "Failed to extract HTML tables",
                        $e,
                        $this->email->id
                    );
                }
            }

            $this->email->markAsCompleted();

            ProcessingLog::logSuccess(
                'email_process',
                "Successfully processed email. Extracted {$totalExtracted} rate cards.",
                $this->email->id,
                ['total_extracted' => $totalExtracted]
            );
        } catch (Exception $e) {
            $this->email->markAsFailed();

            ProcessingLog::logFailure(
                'email_process',
                "Failed to process email",
                $e,
                $this->email->id
            );

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Exception $exception): void
    {
        $this->email->markAsFailed();

        ProcessingLog::logFailure(
            'error',
            "Job failed after {$this->tries} attempts",
            $exception,
            $this->email->id
        );
    }
}
