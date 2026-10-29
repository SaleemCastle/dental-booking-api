<?php

namespace App\Communications;

use InvalidArgumentException;

final class SafeTemplate
{
    public const VARIABLES = ['patient_name', 'invoice_number', 'amount', 'balance', 'payment_link', 'payment_status'];

    public const DEFAULTS = [
        'payment.recorded' => 'Hello {{patient_name}}, payment {{amount}} for invoice {{invoice_number}} is {{payment_status}}. Balance: {{balance}}.',
        'payment_link.created' => 'Hello {{patient_name}}, invoice {{invoice_number}} has balance {{balance}}. Payment status: {{payment_status}}. Pay securely: {{payment_link}}',
        'payment_link.failed' => 'Hello {{patient_name}}, the payment link for invoice {{invoice_number}} failed. No payment is confirmed by this notice. Remaining balance: {{balance}}. Please contact the clinic.',
        'payment_link.expired' => 'Hello {{patient_name}}, the payment link for invoice {{invoice_number}} has expired. Remaining balance: {{balance}}. Please contact the clinic for a new link.',
    ];

    public function validate(string $template): void
    {
        $remaining = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($match) {
            if (! in_array($match[1], self::VARIABLES, true)) {
                throw new InvalidArgumentException('Unsupported template variable.');
            }

            return '';
        }, $template);
        if (strlen($template) > 2000 || preg_match('/[{}<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $remaining)) {
            throw new InvalidArgumentException('Templates must be plain text with allowlisted variables.');
        }
    }

    public function render(string $template, array $variables): string
    {
        $this->validate($template);

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($match) =>
            // Values are substituted once, never evaluated as PHP, Blade, HTML or another template.
            preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) ($variables[$match[1]] ?? '')), $template);
    }
}
