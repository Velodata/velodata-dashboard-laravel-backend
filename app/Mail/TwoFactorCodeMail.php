<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TwoFactorCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $code;
    public $context;

    public function __construct($code, array $context = [])
    {
        $this->code = $code;
        $this->context = $context;
    }

    public function build()
    {
        $appName = $this->context['app_name'] ?? config('app.name', 'Velodata Dashboard');

        return $this->subject($appName . ' 2FA verification code')
            ->view('emails.2fa')
            ->with([
                'code' => $this->code,
                'context' => $this->context,
            ]);
    }
}
