<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Invitation à rejoindre une entreprise ; envoyée uniquement après validation de la transaction (ADM-001). */
final class InvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $companyName,
        public readonly string $roleName,
        public readonly string $token,
        public readonly string $expiresAt,
    ) {
        $this->afterCommit();
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invitation à rejoindre {$this->companyName} sur FDS Business");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invitation', with: [
            'url' => rtrim((string) config('app.frontend_url'), '/').'/invitation/'.$this->token,
        ]);
    }
}
