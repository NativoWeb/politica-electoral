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
            } elseif ($this->isTypedValueKey($name) && $this->hasTypeSibling($data)) {
                $kind = $this->siblingType($data);
                if ($kind === 'last4') {
                    $out[$key.self::LAST4_SUFFIX] = $this->last4($value);
                } elseif ($kind === 'email') {
                    $out[$key] = $this->email($value);
                } else {
                    $out[$key] = $value === null ? null : $this->placeholder();
                }
            } elseif (str_ends_with($name, self::LAST4_SUFFIX) && $this->isLast4Base(substr($name, 0, -strlen(self::LAST4_SUFFIX)))) {
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

    private function isTypedValueKey(string $name): bool
    {
        return in_array($name, array_map('strtolower', config('audit.mask.by_type.keys', [])), true);
    }

    private function isLast4Base(string $name): bool
    {
        return $this->matches($name, 'last4') || $this->isTypedValueKey($name);
    }

    // Without a type/tipo sibling a `value` is not a contact (e.g. vote counts) and is kept as is.
    private function hasTypeSibling(array $siblings): bool
    {
        foreach (config('audit.mask.by_type.type_keys', []) as $typeKey) {
            if (array_key_exists($typeKey, $siblings)) {
                return true;
            }
        }

        return false;
    }

    // 'last4', 'email' or null, from the sibling type/tipo key of a typed contact value.
    private function siblingType(array $siblings): ?string
    {
        foreach (config('audit.mask.by_type.type_keys', []) as $typeKey) {
            $type = $siblings[$typeKey] ?? null;
            if (! is_string($type)) {
                continue;
            }
            $type = strtolower(trim($type));
            if (in_array($type, config('audit.mask.by_type.last4_types', []), true)) {
                return 'last4';
            }
            if (in_array($type, config('audit.mask.by_type.email_types', []), true)) {
                return 'email';
            }
        }

        return null;
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
