<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Alerte envoyée à chaque connexion d'un super-administrateur (D-040, L1-05). */
final class SuperAdminLoginAlert extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $userName,
        public readonly string $ipAddress,
        public readonly string $userAgent,
        public readonly string $occurredAt,
    ) {
        $this->afterCommit();
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Connexion à votre compte super-administrateur FDS Business');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.super-admin-login');
    }
}
