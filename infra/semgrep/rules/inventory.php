<?php
// Familia `inventory`. Cada `ruleid:` DEBE saltar; cada `ok:` NO.

// ── atlanticerp-raw-sql-interpolation (inventario) ───────────────────────────────────
function raw_sql($query, string $column, string $dir, string $term)
{
    // ruleid: atlanticerp-raw-sql-interpolation
    $query->orderByRaw("LOWER({$column}) {$dir}");
    // ruleid: atlanticerp-raw-sql-interpolation
    $query->whereRaw("name LIKE '%$term%'");
    // ruleid: atlanticerp-raw-sql-interpolation
    $query->orderByRaw(self::SORTS[$column] . " {$dir} NULLS LAST");
    // ruleid: atlanticerp-raw-sql-interpolation
    DB::select('SELECT ' . $column . ' FROM t');

    // ok: atlanticerp-raw-sql-interpolation
    $query->orderByRaw('LOWER(name) ASC');
    // ok: atlanticerp-raw-sql-interpolation
    $query->whereRaw('name LIKE ?', ["%{$term}%"]);
    // ok: atlanticerp-raw-sql-interpolation
    DB::select('SELECT 1 WHERE id = ?', [$term]);
    // ok: atlanticerp-raw-sql-interpolation
    $query->orderBy($column);
}

// ── atlanticerp-ssrf-dynamic-url (inventario) ────────────────────────────────────────
function ssrf_inventory(string $url, $model)
{
    // ruleid: atlanticerp-ssrf-dynamic-url
    Http::get($url);
    // ruleid: atlanticerp-ssrf-dynamic-url
    Http::timeout(15)->get($url);
    // ruleid: atlanticerp-ssrf-dynamic-url
    curl_init($url);

    // ok: atlanticerp-ssrf-dynamic-url
    Http::get('https://api.example.com/x');
    // ok: atlanticerp-ssrf-dynamic-url
    Http::get(config('services.msl.base_url'));
    // ok: atlanticerp-ssrf-dynamic-url
    Http::post(self::ENDPOINT, []);
    // ok: atlanticerp-ssrf-dynamic-url
    $model->whereIn('id', [1])->get(['id']);   // un ->get() de Eloquent NO es una petición HTTP
    // ok: atlanticerp-ssrf-dynamic-url
    $coll->get($url);                           // ni el de una colección
}
