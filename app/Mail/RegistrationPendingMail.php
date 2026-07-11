<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RegistrationPendingMail extends Mailable
{
    public function __construct(
        public User $registrant,
    ) {}

    public function envelope(): Envelope
    {
        $label = $this->registrant->role === 'staff' ? 'Staff' : 'CR';

        return new Envelope(
            subject: "New {$label} Account Pending Approval - University Venue Booking System",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.registration-pending',
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
