<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Email;
use App\Models\Invoice;
use Illuminate\Support\Str;

/**
 * Automates the two things a human does on the Invoice Entry page: reading
 * the scanned invoice text into structured fields, and knowing whether the
 * client has actually raised a problem with this particular invoice.
 *
 * Extraction always runs and only ever returns suggested field values - it
 * never saves anything, InvoiceController::update still needs a human to
 * hit Save. The dispute check is a plain keyword match against the emails
 * table (no AI, no false negatives from a model being creative) - if a
 * client email mentions this supplier, that's surfaced so a human doesn't
 * blindly save-and-pay something that's already being disputed.
 */
class InvoiceAgentService
{
    public function __construct(private Anthropic $anthropic) {}

    public function extract(Invoice $invoice): array
    {
        return [
            'fields' => $this->extractFields($invoice),
            'dispute' => $this->findDispute($invoice),
        ];
    }

    /**
     * The actual write: reads the scan, saves it as a booked entry, but
     * marks it disputed instead of clean. Used by EmailAgentController::act
     * once a human has approved doing this off the back of a client email -
     * it's the one place in this feature that persists anything.
     */
    public function holdAsDisputed(Invoice $invoice, string $disputedNote): Invoice
    {
        $fields = $this->extractFields($invoice);

        $invoice->update([
            'supplier' => $fields['supplier'] ?? $invoice->supplier,
            'invoice_date' => $fields['invoice_date'] ?? $invoice->invoice_date,
            'total' => $fields['total'] ?? $invoice->total,
            'category_id' => $fields['category_id'] ?? $invoice->category_id,
            'entered_at' => now(),
            'disputed_note' => $disputedNote,
        ]);

        $invoice->lines()->delete();
        $invoice->lines()->createMany($fields['lines'] ?? []);

        return $invoice->load('lines');
    }

    /**
     * Reverse of findDispute: given a client email, find the invoice it's
     * plausibly talking about. Same "dumb on purpose" keyword approach - a
     * few generic words are excluded so "invoice update" doesn't match
     * everything in the folder.
     */
    public function matchInvoiceForEmail(Email $email): ?Invoice
    {
        $stopwords = ['invoice', 'invoices', 'account', 'accounts', 'update', 'updated', 'urgent', 'payment', 'details', 'please'];

        $words = collect(preg_split('/[^a-z]+/', Str::lower($email->subject.' '.$email->body)))
            ->filter(fn ($w) => strlen($w) >= 6 && ! in_array($w, $stopwords, true))
            ->unique();

        foreach ($words as $word) {
            $invoice = Invoice::query()
                ->whereRaw('LOWER(filename) LIKE ?', ["%{$word}%"])
                ->orWhereRaw('LOWER(raw_text) LIKE ?', ["%{$word}%"])
                ->first();

            if ($invoice) {
                return $invoice;
            }
        }

        return null;
    }

    private function extractFields(Invoice $invoice): array
    {
        $categories = Category::pluck('name');

        $system = <<<SYS
        You read scanned supplier invoices for a farm accounting firm and turn them
        into structured entry-form data. Respond with ONLY a JSON object, no other
        text, no markdown fences:
        {
          "supplier": "...",
          "invoice_date": "YYYY-MM-DD",
          "total": number (the GST-inclusive total due),
          "category": one of {$categories->implode(', ')},
          "lines": [{"description": "...", "amount": number}, ...] (excl. GST, one per line item)
        }

        Pick whichever category from the list best fits what was purchased. Use the
        line item amounts as printed (excl. GST) - do not add GST to them yourself.
        SYS;

        $raw = $this->anthropic->complete($invoice->raw_text, $system, 1024);

        $trimmed = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($raw)));
        $parsed = json_decode($trimmed, true);

        if (! is_array($parsed)) {
            return [];
        }

        $categoryId = Category::whereRaw('LOWER(name) = ?', [Str::lower($parsed['category'] ?? '')])->value('id');

        return [
            'supplier' => $parsed['supplier'] ?? null,
            'invoice_date' => $parsed['invoice_date'] ?? null,
            'total' => $parsed['total'] ?? null,
            'category_id' => $categoryId,
            'lines' => $parsed['lines'] ?? [],
        ];
    }

    /**
     * Look for a client email that names this supplier. Deliberately dumb
     * (substring match on the first word of the supplier name) rather than
     * AI-judged - a dispute flag needs to be predictable, not a model's
     * best guess.
     */
    private function findDispute(Invoice $invoice): ?array
    {
        $supplierWord = Str::of($invoice->supplier ?? $invoice->filename)
            ->replace('-', ' ')
            ->explode(' ')
            ->first(fn ($word) => strlen($word) > 3);

        if (! $supplierWord) {
            return null;
        }

        $email = Email::query()
            ->where(function ($q) use ($supplierWord) {
                $q->whereRaw('LOWER(subject) LIKE ?', ['%'.Str::lower($supplierWord).'%'])
                    ->orWhereRaw('LOWER(body) LIKE ?', ['%'.Str::lower($supplierWord).'%']);
            })
            ->orderByDesc('received_at')
            ->first();

        if (! $email) {
            return null;
        }

        return [
            'email_id' => $email->id,
            'from_name' => $email->from_name,
            'subject' => $email->subject,
            'snippet' => Str::limit($email->body, 160),
        ];
    }
}
