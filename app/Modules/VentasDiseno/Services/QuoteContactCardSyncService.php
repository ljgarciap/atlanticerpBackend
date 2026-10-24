<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SubClientContact;
use Illuminate\Support\Str;

/**
 * SCRUM-1121 (corrección de Daniela, 2026-10-08) — los contactos de una cotización (`SubClientContact`, pivote `quote_contacts`) y los de la
 * tarjeta de Pipeline (`PipelineCardContact`, el campo «Contacto» del detalle) son tablas distintas, sin identidad compartida. Hasta ahora
 * solo se copiaban en el otro sentido (tarjeta → cotización al crearla); al confirmar una cotización el contacto quedaba solo en la
 * cotización y había que reingresarlo a mano en Pipeline.
 *
 * Regla (decisión de Luis, 2026-10-09): SOLO AGREGAR. Se copian a la tarjeta los contactos de la cotización que todavía no están; nunca se
 * borra ni se modifica un contacto de la tarjeta (alguien pudo cargarlo a mano en Pipeline). Idempotente: correr de nuevo no duplica.
 *
 * Un contacto ya está en la tarjeta si coincide por correo, o por teléfono (solo dígitos), o por nombre + rol (sin acentos ni mayúsculas).
 * Un correo o teléfono vacío nunca cuenta como coincidencia.
 */
final class QuoteContactCardSyncService
{
    /** Cuántos dígitos como mínimo para que un teléfono sirva de clave de coincidencia (evita que «0» o «1» unan contactos distintos). */
    private const MIN_PHONE_DIGITS = 6;
    private const PHONE_TAIL_DIGITS = 8;

    /**
     * Contactos de la cotización que todavía NO están en la tarjeta (sin escribir nada). Dos contactos de la cotización que son el mismo
     * entre sí tampoco se copian dos veces.
     *
     * @return list<SubClientContact>
     */
    public function missing(Quote $quote, PipelineCard $card): array
    {
        $emails = $phones = $nameRoles = [];
        foreach ($card->contacts()->get() as $c) {
            $this->remember($emails, $phones, $nameRoles, $c->email, $c->phone, $c->name, $c->role);
        }

        $missing = [];
        foreach ($quote->contacts()->get() as $contact) {
            $email = $this->email($contact->email);
            $phone = $this->phone($contact->phone);
            $nameRole = $this->nameRole($contact->name, $contact->role);

            if (($email !== null && isset($emails[$email])) || ($phone !== null && isset($phones[$phone])) || isset($nameRoles[$nameRole])) {
                continue;
            }

            $missing[] = $contact;
            $this->remember($emails, $phones, $nameRoles, $contact->email, $contact->phone, $contact->name, $contact->role);
        }

        return $missing;
    }

    /** @return int cuántos contactos se agregaron a la tarjeta */
    public function sync(Quote $quote, PipelineCard $card): int
    {
        $missing = $this->missing($quote, $card);

        foreach ($missing as $contact) {
            $card->contacts()->create([
                'name'  => $contact->name,
                'role'  => $contact->role,
                'phone' => $contact->phone,
                'email' => $contact->email,
            ]);
        }

        if ($missing !== []) {
            $card->unsetRelation('contacts');
        }

        return count($missing);
    }

    /**
     * @param array<string, true> $emails
     * @param array<string, true> $phones
     * @param array<string, true> $nameRoles
     */
    private function remember(array &$emails, array &$phones, array &$nameRoles, ?string $email, ?string $phone, string $name, string $role): void
    {
        if (($e = $this->email($email)) !== null) {
            $emails[$e] = true;
        }
        if (($p = $this->phone($phone)) !== null) {
            $phones[$p] = true;
        }
        $nameRoles[$this->nameRole($name, $role)] = true;
    }

    private function email(?string $value): ?string
    {
        $v = Str::lower(trim((string) $value));

        return $v === '' ? null : $v;
    }

    private function phone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        // «+507 6222-2222» y «6222-2222» son el mismo número: se comparan los últimos 8 dígitos, sin el prefijo del país.
        $digits = strlen($digits) > self::PHONE_TAIL_DIGITS ? substr($digits, -self::PHONE_TAIL_DIGITS) : $digits;

        return strlen($digits) >= self::MIN_PHONE_DIGITS ? $digits : null;
    }

    private function nameRole(string $name, string $role): string
    {
        return Str::lower(Str::ascii(trim((string) preg_replace('/\s+/u', ' ', $name)))).'|'.$role;
    }
}
