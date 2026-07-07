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

        // mail.dtech.co.tz ni shared hosting - cheti chake cha SSL kimesajiliwa
        // kwa jina la server ya hosting (siyo "mail.dtech.co.tz" lenyewe), hivyo
        // hostname verification ya kawaida ya Laravel inashindwa hata kama
        // credentials ni sahihi. Tunajenga SMTP transport wenyewe hapa ili
        // kuzima "verify_peer_name" pekee (cheti chenyewe bado kinathibitishwa).
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
