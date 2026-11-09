<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** APR-009 : notification par e-mail d'une demande d'approbation (envoi en file d'attente). */
final class ApprovalRequestedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $number, public readonly string $summary, public readonly string $requester, public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Demande d'approbation {$this->number}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.approval-requested');
    }
}
