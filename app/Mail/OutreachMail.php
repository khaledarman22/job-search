<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OutreachMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
        public string $fromEmail,
        public string $fromName,
        public ?string $cvPath = null,
        public ?string $cvName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }

    public function attachments(): array
    {
        if (! $this->cvPath) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $this->cvPath)
                ->as($this->cvName ?? 'CV.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
