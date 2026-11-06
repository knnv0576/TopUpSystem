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

/** 寄給玩家留下的聯絡信箱：客服已回覆。 */
class SupportReplyToPlayer extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket, public SupportMessage $supportMessage) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "客服已回覆你的問題：{$this->ticket->subject}（{$this->ticket->ticket_no}）");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.support-reply');
    }
}
