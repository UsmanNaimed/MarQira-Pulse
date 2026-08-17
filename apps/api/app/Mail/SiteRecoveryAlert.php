<?php

namespace App\Mail;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your site is back online" recovery alert.
 *
 * Sent once when a site that was offline sends a heartbeat again. Queued (Redis)
 * so mail delivery never blocks the heartbeat request path.
 */
class SiteRecoveryAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param Site                    $site         The recovered site.
     * @param \Carbon\CarbonInterface|null $offlineSince When the offline episode began.
     * @param int                     $alertsSent   How many offline alerts were sent during the episode.
     */
    public function __construct(
        public Site $site,
        public $offlineSince = null,
        public int $alertsSent = 0,
    ) {
    }

    public function envelope(): Envelope
    {
        $domain = $this->site->domain ?: ($this->site->domain_normalized ?: 'your site');

        return new Envelope(
            subject: sprintf('[MarQira Pulse] Site recovered: %s', $domain),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.site-recovery',
            with: [
                'site' => $this->site,
                'offlineSince' => $this->offlineSince,
                'alertsSent' => $this->alertsSent,
                'recoveredAt' => $this->site->last_heartbeat_at ?? now(),
            ],
        );
    }
}
