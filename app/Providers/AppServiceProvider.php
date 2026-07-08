<?php

namespace App\Providers;

use App\Mail\Transport\BrevoTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('brevo', function () {
            return new BrevoTransport(config('services.brevo.key'));
        });

        // mail.dtech.co.tz is shared hosting - its SSL certificate is issued
        // for the hosting server's own name (not "mail.dtech.co.tz" itself),
        // so Laravel's normal hostname verification fails even when the
        // credentials are correct. We build the SMTP transport ourselves here
        // to disable only "verify_peer_name" (the certificate itself is still verified).
        Mail::extend('smtp', function (array $config = []) {
            $config = array_merge(config('mail.mailers.smtp'), $config);
            $scheme = $config['scheme'] ?? (($config['port'] ?? null) == 465 ? 'smtps' : 'smtp');

            $transport = (new EsmtpTransportFactory)->create(new Dsn(
                $scheme,
                $config['host'],
                $config['username'] ?? null,
                $config['password'] ?? null,
                $config['port'] ?? null,
                $config
            ));

            $stream = $transport->getStream();

            if ($stream instanceof SocketStream) {
                $stream->setStreamOptions(array_replace_recursive($stream->getStreamOptions(), [
                    'ssl' => [
                        'allow_self_signed' => true,
                        'verify_peer' => true,
                        'verify_peer_name' => false,
                    ],
                ]));
            }

            return $transport;
        });
    }
}
