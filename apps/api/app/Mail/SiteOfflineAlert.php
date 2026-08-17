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
 * "Your site is offline" alert.
 *
 * Sent when a site is first detected offline and again on each repeat cycle
 * while it stays offline. Queued (Redis) so a slow SMTP server never blocks the
 * scheduler run that dispatches it.
 */
class SiteOfflineAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param Site $site        The offline site (kept minimal via SerializesModels).
     * @param int  $alertNumber 1 for the first alert, 2+ for repeats.
     */
    public function __construct(
        public Site $site,
        public int $alertNumber = 1,
    ) {
    }

    public function envelope(): Envelope
    {
        $domain = $this->site->domain ?: ($this->site->domain_normalized ?: 'your site');

        $subject = $this->alertNumber > 1
            ? sprintf('[MarQira Pulse] Still offline: %s (alert #%d)', $domain, $this->alertNumber)
            : sprintf('[MarQira Pulse] Site offline: %s', $domain);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.site-offline',
            with: [
                'site' => $this->site,
                'alertNumber' => $this->alertNumber,
                'lastSeen' => $this->site->last_heartbeat_at,
                'offlineSince' => $this->site->offline_since,
            ],
        );
    }
}
