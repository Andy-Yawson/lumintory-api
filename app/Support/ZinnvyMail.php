<?php

namespace App\Support;

use Illuminate\Support\Facades\Mail;

/**
 * Turns an email's plain-text body into the standard Zinnvy HTML email (see zinnvy.blade.php) plus a clean text alternative.
 * Existing call sites keep passing plain text: paragraphs, "- " lists, "Label: value" blocks and "Open the order: https://..." lines
 * are recognised and laid out. For anything explicit, put [[button: Label | https://url]] on its own line, or [[note: text]].
 *
 * Options: product (header label), from (small line above the title, e.g. the shop's name), title, preheader, reason (why they got it).
 */
final class ZinnvyMail
{
    private const BUTTON_LINE = '/^(Open|View|Track|Review|Confirm|Reset|Sign in|Go to|Pay|Accept|Verify|Download|Get started)[^:\n]{0,50}:\s*(https?:\/\/\S+)$/i';

    /**
     * Sends a standard Zinnvy email right away (use inside a queued job if you need it queued). $to is an address or a list of them.
     *
     * @param  array{product?: string, from?: ?string, title?: ?string, preheader?: ?string, reason?: ?string}  $options
     */
    public static function send(string|array $to, string $subject, string $body, array $options = []): void
    {
        Mail::send([], [], function ($message) use ($to, $subject, $body, $options) {
            $logo = $message->embed(resource_path('email/zinnvy-mark.png'));
            $message->to($to)->subject($subject)->html(self::html($subject, $body, $options, $logo))->text(self::text($subject, $body, $options));
        });
    }

    /** @param  array{product?: string, from?: ?string, title?: ?string, preheader?: ?string, reason?: ?string}  $o */
    public static function html(string $subject, string $body, array $o, string $logo): string
    {
        [$blocks, $first] = self::blocks($body);

        return view('emails.zinnvy', [
            'logo' => $logo,
            'product' => $o['product'] ?? 'Inventory',
            'from' => $o['from'] ?? null,
            'title' => $o['title'] ?? $subject,
            'preheader' => $o['preheader'] ?? $first,
            'reason' => $o['reason'] ?? null,
            'blocks' => $blocks,
        ])->render();
    }

    public static function text(string $subject, string $body, array $o = []): string
    {
        $clean = self::clean($body);
        $clean = preg_replace('/\[\[button:\s*(.*?)\s*\|\s*(.*?)\s*\]\]/i', '$1: $2', $clean);
        $clean = preg_replace('/\[\[note:\s*(.*?)\s*\]\]/i', '$1', $clean);

        return trim($clean)."\n\n— Zinnvy ".($o['product'] ?? 'Inventory').($o['reason'] ?? null ? "\n".$o['reason'] : '')."\nzinnvy.com · support@zinnvytech.com\n";
    }

    /** Drops the old hand-written sign-off ("Regards, Zinnvy."): the layout has a footer. */
    private static function clean(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", trim($body));
        $body = preg_replace('/\n+\s*(Regards|Thanks|Thank you|Best|Cheers|Kind regards),?\s*\n\s*(The )?Zinnvy( team)?\.?\s*$/i', '', $body);
        $body = preg_replace('/\n+\s*(The )?Zinnvy( team)?\.\s*$/i', '', $body);

        return trim($body);
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: string} blocks and the first sentence (for the inbox preview) */
    private static function blocks(string $body): array
    {
        $blocks = [];
        $first = '';
        foreach (preg_split('/\n{2,}/', self::clean($body)) as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            $lines = array_values(array_filter(array_map('trim', explode("\n", $para)), fn ($l) => $l !== ''));

            // Explicit markers, one per line.
            if (preg_match('/^\[\[(button|note):/i', $lines[0])) {
                foreach ($lines as $l) {
                    if (preg_match('/^\[\[button:\s*(.*?)\s*\|\s*(https?:\/\/[^\]\s]+)\s*\]\]$/i', $l, $m)) {
                        $blocks[] = ['type' => 'button', 'label' => $m[1], 'url' => $m[2]];
                    } elseif (preg_match('/^\[\[note:\s*(.*?)\s*\]\]$/i', $l, $m)) {
                        $blocks[] = ['type' => 'note', 'html' => self::inline($m[1])];
                    }
                }

                continue;
            }

            // "Open the order: https://..." on its own line becomes a button.
            if (count(array_filter($lines, fn ($l) => preg_match(self::BUTTON_LINE, $l))) === count($lines)) {
                foreach ($lines as $l) {
                    preg_match(self::BUTTON_LINE, $l, $m);
                    $blocks[] = ['type' => 'button', 'label' => trim(explode(':', $l, 2)[0]), 'url' => $m[2]];
                }

                continue;
            }

            if (count(array_filter($lines, fn ($l) => preg_match('/^[-•*]\s+/', $l))) === count($lines)) {
                $blocks[] = ['type' => 'ul', 'items' => array_map(fn ($l) => self::inline(preg_replace('/^[-•*]\s+/', '', $l)), $lines)];

                continue;
            }

            // Two or more short "Label: value" lines read as a details card.
            $rows = [];
            foreach ($lines as $l) {
                if (preg_match('/^([^:\n]{2,28}):\s+(.+)$/', $l, $m) && ! preg_match('/^https?$/i', $m[1])) {
                    $rows[] = [$m[1], self::inline($m[2])];
                }
            }
            if (count($rows) >= 2 && count($rows) === count($lines)) {
                $blocks[] = ['type' => 'kv', 'rows' => $rows];

                continue;
            }

            $blocks[] = ['type' => 'p', 'html' => implode('<br>', array_map(fn ($l) => self::inline($l), $lines))];
            $first = $first ?: mb_substr(strip_tags(implode(' ', $lines)), 0, 110);
        }

        return [$blocks, $first];
    }

    /** Escapes, then turns links into links and **bold** into bold. */
    private static function inline(string $s): string
    {
        $s = e($s);
        $s = preg_replace_callback('/https?:\/\/[^\s<]+/', function ($m) {
            $url = rtrim($m[0], '.,;:)!?');
            $tail = substr($m[0], strlen($url));

            return '<a href="'.$url.'" style="color:#1f5a45;text-decoration:underline;word-break:break-all;">'.$url.'</a>'.$tail;
        }, $s);

        return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    }
}
