<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Lien de réinitialisation du mot de passe : usage unique, valable 60 minutes (L1-06). */
final class PasswordResetMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $userName,
        public readonly string $email,
        public readonly string $token,
    ) {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Réinitialisation de votre mot de passe FDS Business');
    }

    public function content(): Content
    {
        $query = http_build_query(['token' => $this->token, 'email' => $this->email]);

        return new Content(view: 'mail.password-reset', with: [
            'url' => rtrim((string) config('app.frontend_url'), '/').'/reinitialiser-mot-de-passe?'.$query,
        ]);
    }
}
