<?php

namespace App\Services\Audit;

use Illuminate\Support\Str;

// Reduces personal data in audit payloads by key name (rules in config/audit.php). Idempotent.
class AuditDataMasker
{
    private const LAST4_SUFFIX = '_last4';

    public function mask(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                $out[$key] = is_array($value) ? $this->mask($value) : $value;

                continue;
            }

            $name = strtolower($key);

            if ($this->matches($name, 'omit')) {
                $out[$key] = $value === null ? null : $this->placeholder();
            } elseif (str_ends_with($name, self::LAST4_SUFFIX) && $this->matches(substr($name, 0, -strlen(self::LAST4_SUFFIX)), 'last4')) {
                $out[$key] = $this->last4($value);
            } elseif ($this->matches($name, 'last4')) {
                $out[$key.self::LAST4_SUFFIX] = $this->last4($value);
            } elseif ($this->matches($name, 'email')) {
                $out[$key] = $this->email($value);
            } else {
                $out[$key] = is_array($value) ? $this->mask($value) : $value;
            }
        }

        return $out;
    }

    private function matches(string $name, string $group): bool
    {
        return Str::is(array_map('strtolower', config("audit.mask.{$group}", [])), $name);
    }

    private function last4(mixed $value): ?string
    {
        if ($value === null || is_bool($value)) {
            return null;
        }
        if (! is_string($value) && ! is_int($value)) {
            return $this->placeholder();
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : substr($digits, -4);
    }

    private function email(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! str_contains($value, '@')) {
            return $this->placeholder();
        }

        $at = strrpos($value, '@');

        return mb_substr(substr($value, 0, $at), 0, 1).'***'.substr($value, $at);
    }

    private function placeholder(): string
    {
        return (string) config('audit.omitted_placeholder', '[omitido]');
    }
}
