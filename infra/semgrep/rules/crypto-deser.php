<?php
// Familia `crypto-deser`. Cada `ruleid:` DEBE saltar; cada `ok:` NO.

// ── atlanticerp-weak-hash ────────────────────────────────────────────────────────────
function weak_hash(string $s)
{
    // ruleid: atlanticerp-weak-hash
    md5($s);
    // ruleid: atlanticerp-weak-hash
    sha1($s);

    // ok: atlanticerp-weak-hash
    hash('sha256', $s);
    // ok: atlanticerp-weak-hash
    hash_hmac('sha256', $s, 'k');
}

// ── atlanticerp-unserialize ──────────────────────────────────────────────────────────
function unser(string $s)
{
    // ruleid: atlanticerp-unserialize
    unserialize($s);

    // ok: atlanticerp-unserialize
    unserialize($s, ['allowed_classes' => false]);
    // ok: atlanticerp-unserialize
    json_decode($s, true);
}
