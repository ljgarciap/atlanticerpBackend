#!/usr/bin/env python3
"""route-authz-matrix.py — SCRUM-959/1040: inventario de autorización por ruta, desde `artisan route:list --json`.

Clasifica cada ruta `api/*` por el middleware de autorización que EXIGE en la ruta (`permission:`, `role:`). Las rutas sin ninguno se
dividen en: públicas por diseño (lista cerrada abajo) y "revisar" (el control, si existe, está dentro del controlador: hay que
verificarlo a mano). NO prueba nada por sí mismo: es el inventario sobre el que se escriben las pruebas por rol.

Uso:  route-authz-matrix.py <routes.json> <salida.md>     (imprime un resumen de una línea por stdout)
"""
import collections
import json
import re
import sys

# Públicas por diseño (sin sesión): se revisan en el threat model de Auth y de archivos, no aquí.
PUBLIC = [
    r"^api/auth/(login|refresh|logout|forgot-password|reset-password|register|departments|mfa/verify|mfa/resend)$",
    r"^api/health(/deep)?$",
    r"^api/documentation$", r"^api/oauth2-callback$",
]


def main(src: str, out: str) -> None:
    routes = [r for r in json.load(open(src)) if r["uri"].startswith("api/")]
    rows = []
    for r in routes:
        uri, method = r["uri"], r["method"].split("|")[0]
        mw = r.get("middleware") or []
        gates = [m for m in mw if m.startswith(("permission:", "role:"))]
        public = any(re.match(p, uri) for p in PUBLIC)
        kind = "con permiso/rol" if gates else ("pública por diseño" if public else "REVISAR (sin permiso/rol en la ruta)")
        rows.append((uri.split("/")[1], method, uri, kind, "; ".join(gates)))

    total = len(rows)
    kinds = collections.Counter(r[3] for r in rows)
    revisar = [r for r in rows if r[3].startswith("REVISAR")]
    by_mod = collections.Counter(r[0] for r in revisar)
    mutating = [r for r in revisar if r[1] in ("POST", "PUT", "PATCH", "DELETE")]

    with open(out, "w") as f:
        f.write("# Matriz de autorización por ruta\n\n")
        f.write(f"Rutas `api/*`: **{total}** · con permiso/rol: **{kinds['con permiso/rol']}** · públicas por diseño: **{kinds['pública por diseño']}** · "
                f"**a revisar: {len(revisar)}** (de ellas {len(mutating)} escriben datos).\n\n")
        f.write("Las rutas a revisar pueden tener el control dentro del controlador: este inventario solo marca que la RUTA no lo declara.\n\n")
        f.write("## A revisar por módulo\n\n| Módulo | Rutas |\n|---|---|\n")
        for mod, n in by_mod.most_common():
            f.write(f"| {mod} | {n} |\n")
        f.write("\n## Detalle (a revisar)\n\n| Método | Ruta |\n|---|---|\n")
        for _, m, u, _, _ in sorted(revisar, key=lambda x: (x[0], x[2], x[1])):
            f.write(f"| {m} | `{u}` |\n")
        f.write("\n## Por módulo (todas)\n\n| Módulo | Total | Con permiso/rol | Públicas | A revisar |\n|---|---|---|---|---|\n")
        mods = collections.defaultdict(collections.Counter)
        for mod, _, _, kind, _ in rows:
            mods[mod][kind] += 1
        for mod in sorted(mods, key=lambda m: -sum(mods[m].values())):
            c = mods[mod]
            f.write(f"| {mod} | {sum(c.values())} | {c['con permiso/rol']} | {c['pública por diseño']} | {sum(v for k, v in c.items() if k.startswith('REVISAR'))} |\n")
    print(f"authz-matrix: {total} rutas api, {kinds['con permiso/rol']} con permiso/rol, {kinds['pública por diseño']} públicas por diseño, {len(revisar)} a revisar ({len(mutating)} escriben datos)")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
