<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Models\Architect;
use Illuminate\Database\Eloquent\Builder;

/**
 * SCRUM-1121 (decisión D2 de Luis) — detección de duplicados de la base única de asociados (`architects`).
 *
 * - EXACTO: mismo correo, mismo teléfono o mismo documento que otro asociado → no se crea; se ofrece usar el existente.
 * - PARECIDO: mismo nombre una vez normalizado (sin acentos, mayúsculas ni espacios sobrantes) → solo se avisa y el usuario decide.
 *
 * Normalización: correo en minúsculas; teléfono solo dígitos; documento sin separadores y en minúsculas; nombre sin acentos
 * (`public.f_unaccent`), en minúsculas y con espacios colapsados. Un dato vacío NUNCA cuenta como coincidencia. Sin umbrales numéricos:
 * es igualdad normalizada, no similitud difusa.
 */
final class AssociateDuplicateFinder
{
    /**
     * @param array{name?: string|null, email?: string|null, phone?: string|null, document?: string|null} $data
     * @return array{exact: list<Architect>, similar: list<Architect>}
     */
    public function find(array $data, ?int $excludeId = null, bool $includeSimilar = true): array
    {
        $email    = $this->email($data['email'] ?? null);
        $phone    = $this->digits($data['phone'] ?? null);
        $document = $this->document($data['document'] ?? null);

        $exact = [];
        if ($email !== '' || $phone !== '' || $document !== '') {
            /** @var Builder<Architect> $query */
            $query = Architect::query()->where(function (Builder $q) use ($email, $phone, $document): void {
                if ($email !== '') {
                    $q->orWhereRaw('lower(trim(email)) = ?', [$email]);
                }
                if ($phone !== '') {
                    $q->orWhereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') = ?", [$phone]);
                }
                if ($document !== '') {
                    $q->orWhereRaw("regexp_replace(lower(coalesce(document, '')), '[^a-z0-9]', '', 'g') = ?", [$document]);
                }
            });
            if ($excludeId !== null) {
                $query->whereKeyNot($excludeId);
            }
            $exact = array_values($query->orderBy('name')->limit(10)->get()->all());
        }

        $similar = [];
        $name    = $this->name($data['name'] ?? null);
        if ($includeSimilar && $name !== '') {
            /** @var Builder<Architect> $query */
            $query = Architect::query()
                ->whereRaw("public.f_unaccent(lower(regexp_replace(trim(name), '\\s+', ' ', 'g'))) = public.f_unaccent(?)", [$name]);
            if ($excludeId !== null) {
                $query->whereKeyNot($excludeId);
            }
            $exactIds = array_map(static fn (Architect $a): int => $a->id, $exact);
            if ($exactIds !== []) {
                $query->whereNotIn('id', $exactIds);
            }
            $similar = array_values($query->orderBy('name')->limit(10)->get()->all());
        }

        return ['exact' => $exact, 'similar' => $similar];
    }

    private function email(?string $v): string
    {
        return mb_strtolower(trim((string) $v));
    }

    private function digits(?string $v): string
    {
        return (string) preg_replace('/[^0-9]/', '', (string) $v);
    }

    private function document(?string $v): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $v));
    }

    /** Nombre sin acentos, en minúsculas y con espacios colapsados (misma forma que la expresión SQL de arriba). */
    private function name(?string $v): string
    {
        return $this->stripAccents(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $v))));
    }

    private function stripAccents(string $v): string
    {
        return strtr($v, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c',
        ]);
    }
}
