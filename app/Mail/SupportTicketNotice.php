<?php

namespace App\Mail;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** 寄給客服信箱：有新工單或玩家回覆。 */
class SupportTicketNotice extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket, public SupportMessage $supportMessage, public string $kind) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[{$this->kind}] {$this->ticket->ticket_no} {$this->ticket->subject}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.support-notice');
    }
}
