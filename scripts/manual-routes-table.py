#!/usr/bin/env python3
"""Tabla markdown de rutas de un módulo para su manual técnico, desde `php artisan route:list --json`.

Uso: python3 scripts/manual-routes-table.py <prefijo> [--json-file ruta]
Sin --json-file ejecuta `docker compose -f infra/docker-compose.yml exec -T laravel php artisan route:list --path=api/<prefijo> --json`
(el stack local debe estar arriba). Agrupa por el primer segmento después del prefijo. Imprime en stdout.
"""
import json
import subprocess
import sys
from pathlib import Path

BACK = Path(__file__).resolve().parent.parent


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    prefix = args[0]
    if '--json-file' in sys.argv:
        rows = json.load(open(sys.argv[sys.argv.index('--json-file') + 1]))
    else:
        out = subprocess.check_output(
            ['docker', 'compose', '-f', str(BACK / 'infra/docker-compose.yml'), 'exec', '-T', 'laravel', 'php', 'artisan',
             'route:list', f'--path=api/{prefix}', '--json'], stdin=subprocess.DEVNULL, text=True)
        rows = json.loads(out)
    groups = {}
    for x in rows:
        uri = '/' + x['uri'].replace('api/', '', 1)
        parts = uri.split('/')
        seg = parts[2] if len(parts) > 2 else '(raíz)'
        meth = x['method'].replace('|HEAD', '')
        ctl = x['action'].split('\\')[-1]
        perm = next((m.replace('permission:', '') for m in x['middleware'] if m.startswith('permission:')), None)
        if perm is None:
            role = next((m for m in x['middleware'] if m.startswith('role:')), None)
            perm = role if role else '(la comprueba el controlador)'
        thr = [m for m in x['middleware'] if m.startswith('throttle')]
        if thr:
            perm += f' · {thr[0]}'
        groups.setdefault(seg, []).append((meth, uri, ctl, perm))
    for seg, items in groups.items():
        print(f'\n**`/{prefix}/{seg}`**\n\n| Método | Ruta | Controlador@método | Permiso (OR) |\n|---|---|---|---|')
        for meth, uri, ctl, perm in items:
            print(f'| {meth} | `{uri}` | `{ctl}` | `{perm}` |')
    print(f'\n<!-- {sum(len(v) for v in groups.values())} rutas -->', file=sys.stderr)


if __name__ == '__main__':
    main()
