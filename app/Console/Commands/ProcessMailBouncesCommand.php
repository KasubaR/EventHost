<?php

namespace App\Console\Commands;

use App\Mail\Bounces\BounceMailbox;
use App\Models\EmailSuppression;
use App\Services\EmailBounceProcessor;
use App\Support\BounceReport;
use Illuminate\Console\Command;

/**
 * Reads bounce reports from the bounce mailbox, marks the email they belong to as bounced and stops guest email to an address
 * that hard-bounced (plans/email-gaps.md Phase 3). Prints counts only: no address and no message text is ever written out.
 */
class ProcessMailBouncesCommand extends Command
{
    protected $signature = 'mail:process-bounces
        {--dry-run : Read and count the reports without changing anything or moving messages}
        {--limit=200 : The most messages to read in one run}';

    protected $description = 'Process bounce reports from the bounce mailbox and stop emailing addresses that hard-bounced';

    public function handle(BounceMailbox $mailbox, EmailBounceProcessor $processor): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
            $pruned = EmailSuppression::query()
                ->where('last_bounced_at', '<', now()->subDays(EmailSuppression::KEEP_DAYS))
                ->delete();

            if ($pruned > 0) {
                $this->line("Dropped {$pruned} suppressed address(es) with no bounce in ".EmailSuppression::KEEP_DAYS.' days.');
            }
        }

        if (blank(config('mail.bounce_imap.host')) || blank(config('mail.bounce_imap.username'))) {
            $this->line('The bounce mailbox is not configured (MAIL_BOUNCE_IMAP_HOST / _USERNAME). Nothing to read.');

            return self::SUCCESS;
        }

        $counts = ['suppressed' => 0, 'soft' => 0, 'unmatched' => 0, 'not_a_bounce' => 0];

        try {
            $read = $mailbox->process(function (string $raw) use ($processor, $dryRun, &$counts): void {
                $report = BounceReport::parse($raw);

                if ($report === null) {
                    $counts['not_a_bounce']++;

                    return;
                }

                if ($dryRun) {
                    $counts[$report->isHard() ? 'suppressed' : 'soft']++;

                    return;
                }

                $counts[$processor->process($report)]++;
            }, max(1, (int) $this->option('limit')), move: ! $dryRun);
        } catch (\Throwable $e) {
            report($e);
            $this->error('Could not read the bounce mailbox: '.class_basename($e).'.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->line("[dry run] Read {$read} message(s); nothing was changed or moved.");
            $this->line('Hard bounces: '.$counts['suppressed'].'. Temporary failures: '.$counts['soft'].'. Not a bounce report: '.$counts['not_a_bounce'].'.');

            return self::SUCCESS;
        }

        $this->line("Read {$read} message(s).");
        $this->line('Stopped email to '.$counts['suppressed'].' address(es) (hard bounce).');
        $this->line('Temporary failures noted: '.$counts['soft'].'.');
        $this->line('Not matched to an email we sent: '.$counts['unmatched'].'.');
        $this->line('Not a bounce report: '.$counts['not_a_bounce'].'.');

        return self::SUCCESS;
    }
}
