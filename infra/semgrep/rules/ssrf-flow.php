<?php
// Familia `ssrf-flow`. Cada `ruleid:` DEBE saltar; cada `ok:` NO.

// ── atlanticerp-ssrf-user-input (flujo) ──────────────────────────────────────────────
function ssrf_flow(\Illuminate\Http\Request $request)
{
    $url = $request->input('url');
    // ruleid: atlanticerp-ssrf-user-input
    Http::get($url);
    // ruleid: atlanticerp-ssrf-user-input
    Http::timeout(10)->get($request->query('callback'));
    // ruleid: atlanticerp-ssrf-user-input
    curl_init($request->input('feed'));

}

function ssrf_constant_url()
{
    // ok: atlanticerp-ssrf-user-input
    Http::get('https://api.example.com/x');
    // ok: atlanticerp-ssrf-user-input
    Http::get(config('services.msl.base_url') . '/v1');
}

function ssrf_tainted_var_unused(\Illuminate\Http\Request $request)
{
    $note = $request->input('note');
    // ok: atlanticerp-ssrf-user-input
    Http::get('https://api.example.com/x', ['note' => $note]);   // el dato va en los parámetros, no en la URL
}
