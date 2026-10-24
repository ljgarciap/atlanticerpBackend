<?php
// Familia `sqli-flow`. Cada `ruleid:` DEBE saltar; cada `ok:` NO.

// ── atlanticerp-sqli-user-input (flujo) ──────────────────────────────────────────────
function sqli_flow(\Illuminate\Http\Request $request, $query)
{
    $sort = $request->input('sort');
    // ruleid: atlanticerp-sqli-user-input
    $query->orderByRaw("{$sort} ASC");

    $term = $request->query('q');
    // ruleid: atlanticerp-sqli-user-input
    $query->whereRaw("name LIKE '%" . $term . "%'");

    // ruleid: atlanticerp-sqli-user-input
    DB::select('SELECT * FROM t WHERE id = ' . $request->input('id'));

    // ok: atlanticerp-sqli-user-input
    $query->whereRaw('name LIKE ?', ["%{$term}%"]);
    // ok: atlanticerp-sqli-user-input
    $query->orderByRaw('LOWER(name) ASC');
}

function sqli_sanitized(\Illuminate\Http\Request $request, $query)
{
    $id = (int) $request->input('id');
    // ok: atlanticerp-sqli-user-input
    $query->whereRaw("id = {$id}");
}
