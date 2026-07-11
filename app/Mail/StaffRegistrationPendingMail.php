<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class StaffRegistrationPendingMail extends Mailable
{
    public function __construct(
        public User $staff,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Staff Account Pending Approval - University Venue Booking System',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-registration-pending',
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
