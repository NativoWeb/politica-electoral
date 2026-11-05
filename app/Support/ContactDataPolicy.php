<?php

namespace App\Support;

// What the WhatsApp agent may hand out of a lider: the contact data (and free-text notes, which often carry it) only
// when the person authorized the processing of their data (autoriza_datos = true). A null or false is a no.
final class ContactDataPolicy
{
    public const FIELDS = ['cedula', 'telefono', 'email', 'observacion'];

    // $lider is a Lider model (boolean cast) or a query row (true/1/'1'/'t' depending on the driver).
    public static function authorized(?object $lider): bool
    {
        return in_array($lider?->autoriza_datos ?? null, [true, 1, '1', 't'], true);
    }

    // $data without FIELDS unless the lider authorized them.
    public static function strip(?object $lider, array $data): array
    {
        return self::authorized($lider) ? $data : array_diff_key($data, array_flip(self::FIELDS));
    }
}
