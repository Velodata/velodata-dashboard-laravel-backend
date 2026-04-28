<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class Test2FAMail extends Mailable
{
    use Queueable, SerializesModels;

    public function build()
    {
        return $this
            ->subject('2FA Test Email from: ReactJS Dashboard')
            ->text('emails.test-2fa-plain');
    }
}
