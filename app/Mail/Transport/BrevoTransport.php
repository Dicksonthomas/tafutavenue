<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

/**
 * Brevo (zamani Sendinblue) hutuma email kupitia HTTPS API (siyo SMTP),
 * kwa sababu Railway inazuia muunganiko wa SMTP (port 587/465) kwenda nje.
 */
class BrevoTransport extends AbstractTransport
{
    public function __construct(private readonly string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();

        if (! $email instanceof Email) {
            throw new TransportException('BrevoTransport inasapoti tu Symfony Mime Email.');
        }

        $from = $email->getFrom()[0];
        $to = array_map(fn ($addr) => [
            'email' => $addr->getAddress(),
            'name' => $addr->getName() ?: null,
        ], $email->getTo());

        $payload = [
            'sender' => [
                'email' => $from->getAddress(),
                'name' => $from->getName() ?: null,
            ],
            'to' => $to,
            'subject' => $email->getSubject(),
            'htmlContent' => $email->getHtmlBody() ?? '<p>'.nl2br(e($email->getTextBody())).'</p>',
        ];

        if ($email->getTextBody()) {
            $payload['textContent'] = $email->getTextBody();
        }

        $response = Http::withHeaders([
            'api-key' => $this->apiKey,
            'Accept' => 'application/json',
        ])->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($response->failed()) {
            throw new TransportException('Brevo API imeshindwa kutuma email: '.$response->body());
        }
    }

    public function __toString(): string
    {
        return 'brevo+api';
    }
}
