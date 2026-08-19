<?php

namespace App\Services;

use App\Models\Email;
use App\Models\Farm;
use App\Models\ReportLine;
use App\Models\StockClass;
use Illuminate\Support\Str;

/**
 * Turns a client email into either a data-backed draft reply or a flag for
 * a human to handle. This is deliberately narrow: it only ever reads from
 * the farm's own numbers (report_lines, stock) and never sends anything or
 * changes any records - EmailController::reply still needs a human click.
 *
 * Two things always flag rather than draft, no matter what Claude says:
 * a sender who doesn't match a known client, and anything Claude itself
 * marks as needing a human (advice, disputes, payment changes, wellbeing).
 */
class EmailAgentService
{
    public function __construct(private Anthropic $anthropic) {}

    public function draft(Email $email): array
    {
        $farm = $this->matchFarm($email);

        if (! $farm) {
            return [
                'action' => 'flag',
                'category' => 'unrecognised_sender',
                'reason' => "This sender doesn't match any client on file ({$email->from_email}). "
                    ."Could be a new contact, or could be spam/phishing - check before doing anything with it, "
                    .'especially if it asks you to pay an invoice or change payment details.',
                'data_points' => [],
            ];
        }

        $context = $this->buildContext($farm);

        $system = <<<SYS
        You are a data-lookup assistant for a farm accounting firm. You read one client
        email at a time and either draft a factual reply using ONLY the numbers given to
        you below, or flag the email for a human to handle yourself.

        You are talking to Kiwi farmers - keep replies short, plain, and warm, matching
        the register of the client's own email. Sign off as "Alex, Southdown Rural
        Accountants".

        Flag instead of drafting (do not attempt a reply) whenever the email:
        - asks for financial, tax, or investment advice or a judgement call (e.g. "can I
          afford this", provisional tax, income equalisation, whether to buy something)
        - disputes an invoice, or asks you to pay something, or asks you to change any
          bank/payment details
        - shows personal distress, grief, or mental health concerns - this always needs a
          human, never an automated reply
        - asks something the data below genuinely doesn't answer
        - is otherwise not a simple factual lookup you can answer from the data given

        Only draft a reply when you can answer using the data below and simple arithmetic
        on it. Always say the number(s) clearly and mention what they're built from
        (e.g. which categories/months). Do not invent figures that aren't in the data.

        Respond with ONLY a JSON object, no other text, no markdown fences:
        {
          "action": "draft" | "flag",
          "category": short_snake_case_label,
          "data_points": [array of the specific figures/rows you used, as strings],
          "reply": "..." (required if action is draft, omit otherwise),
          "reason": "..." (required if action is flag: why a human needs to handle this)
        }
        SYS;

        $prompt = <<<PROMPT
        Client: {$email->from_name} <{$email->from_email}>, {$farm->name} ({$farm->type})
        Subject: {$email->subject}

        Email body:
        {$email->body}

        Data available for this farm (JSON):
        {$context}
        PROMPT;

        $raw = $this->anthropic->complete($prompt, $system, 1024);

        return $this->parseResponse($raw);
    }

    /** Match the sender to a known client by name. No match = flag upstream. */
    private function matchFarm(Email $email): ?Farm
    {
        $farms = Farm::all();

        // Exact/substring match on owner name first (handles "Kate Molloy" in
        // both from_name and any signature).
        foreach ($farms as $farm) {
            if (Str::contains(Str::lower($email->from_name), Str::lower($farm->owner_name))) {
                return $farm;
            }
        }

        // Fall back to matching the surname against the email's local part /
        // domain, in case from_name is unusual.
        foreach ($farms as $farm) {
            $surname = Str::of($farm->owner_name)->afterLast(' ')->lower();
            if (Str::contains(Str::lower($email->from_email), $surname)) {
                return $farm;
            }
        }

        return null;
    }

    /** Build the JSON data bundle the model is allowed to answer from. */
    private function buildContext(Farm $farm): string
    {
        $data = [
            'farm' => [
                'name' => $farm->name,
                'type' => $farm->type,
                'owner' => $farm->owner_name,
            ],
            'report_lines' => ReportLine::where('farm_id', $farm->id)
                ->orderBy('month')
                ->get(['month', 'category', 'budget', 'actual'])
                ->map(fn ($line) => [
                    'month' => $line->month->format('Y-m'),
                    'category' => $line->category,
                    'budget' => $line->budget,
                    'actual' => $line->actual,
                ]),
        ];

        // Stock data only makes sense for the sheep & beef farm - it's the
        // only one with stock_classes/stock_movements rows behind it.
        if ($farm->type === 'Sheep & Beef') {
            $data['stock_classes'] = StockClass::with('movements')
                ->get()
                ->map(fn ($class) => [
                    'name' => $class->name,
                    'opening_count' => $class->opening_count,
                    'closing_count_per_tally_book' => $class->closing_count,
                    'recorded_movements' => $class->movements->map(fn ($m) => [
                        'type' => $m->type,
                        'quantity' => $m->quantity,
                        'note' => $m->note,
                    ]),
                ]);
        }

        return json_encode($data, JSON_PRETTY_PRINT);
    }

    /** Parse Claude's JSON reply defensively - it's still a model, not a database. */
    private function parseResponse(string $raw): array
    {
        $trimmed = trim($raw);
        // Strip markdown fences if the model added them despite instructions.
        $trimmed = preg_replace('/^```(?:json)?|```$/m', '', $trimmed);
        $trimmed = trim($trimmed);

        $parsed = json_decode($trimmed, true);

        if (! is_array($parsed) || ! isset($parsed['action'])) {
            return [
                'action' => 'flag',
                'category' => 'agent_error',
                'reason' => "Couldn't parse a usable response from the assistant - handle this one yourself.",
                'data_points' => [],
            ];
        }

        // Belt and braces: if it claims "draft" but forgot the reply body,
        // treat it as a flag rather than showing an empty draft.
        if ($parsed['action'] === 'draft' && empty($parsed['reply'])) {
            $parsed['action'] = 'flag';
            $parsed['reason'] = 'Assistant tried to draft but returned no reply text - handle this one yourself.';
        }

        return [
            'action' => $parsed['action'],
            'category' => $parsed['category'] ?? 'uncategorised',
            'reply' => $parsed['reply'] ?? null,
            'reason' => $parsed['reason'] ?? null,
            'data_points' => $parsed['data_points'] ?? [],
        ];
    }
}
