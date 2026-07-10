<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingConfirmedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  bool  $approvedByAdmin  False (default) for the system's own
     *                                 auto-approval (no conflict found), true
     *                                 when an Admin manually approved it -
     *                                 the email wording differs accordingly.
     */
    public function __construct(
        public Booking $booking,
        public bool $approvedByAdmin = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Booking Is Confirmed - '.$this->booking->venue->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-confirmed',
            with: ['approvedByAdmin' => $this->approvedByAdmin],
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
