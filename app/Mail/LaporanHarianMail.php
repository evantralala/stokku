<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Email laporan harian: ringkasan di badan email, detail di lampiran Excel. Disusun LaporanHarianService. */
class LaporanHarianMail extends Mailable
{
    /** @param  list<array{judul: string, baris: list<string>}>  $ringkasan */
    public function __construct(
        public string $subjek,
        public array $ringkasan,
        private string $lampiran,
        private string $namaFile,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjek);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.laporan-harian');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->lampiran, $this->namaFile)
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
