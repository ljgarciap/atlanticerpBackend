<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-006, Decisión 2 — Fase A. `can_view` es SMALLINT (0=none, 1=readonly reservado sin usar
 * en Fase A, 2=full) en vez de BOOLEAN — anticipa barato la extensión de "ver sin interactuar"
 * que Luis pidió (2026-07-11), sin necesitar una segunda migración cuando Fase D la defina.
 *
 * Riesgo 4 del ADR (deny-by-default): solo se siembran acá las combinaciones rol×módulo que
 * SCRUM-144 confirma sin ambigüedad ("Qué ve" es la propia tabla de ese ticket — SCRUM-145 define
 * "qué puede hacer", no visibilidad, así que no se usa como fuente acá). Todo lo que el cliente
 * dejó como "a confirmar"/pendiente queda simplemente sin fila — ausencia de fila = no visible,
 * mismo efecto que can_view=0, sin tener que adivinar el valor.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /**
     * @var list<array{role: string, module: string, view_team: bool, note?: string}>
     * can_view_team=true solo donde SCRUM-144 dice explícitamente "todo el equipo" (o es Gerencia,
     * transversal por definición). Vendedor/Diseñador es explícitamente "solo su propia cartera"
     * → view_team=false a propósito, no por omisión.
     */
    private array $visibility = [
        // Gerencia (Mark, David, Whil) — todos los módulos, transversal.
        ['role' => 'gerencia', 'module' => 'ventas_diseno', 'view_team' => true],
        ['role' => 'gerencia', 'module' => 'compras',       'view_team' => true],
        ['role' => 'gerencia', 'module' => 'bodega',        'view_team' => true],
        ['role' => 'gerencia', 'module' => 'servicios',     'view_team' => true],
        ['role' => 'gerencia', 'module' => 'admin_contab',  'view_team' => true],
        ['role' => 'gerencia', 'module' => 'gerencia',      'view_team' => true],
        ['role' => 'gerencia', 'module' => 'operaciones',   'view_team' => true],

        // Líder Admin&Contab (Felix) — Admin&Contab completo. view_team: no confirmado
        // explícitamente como "todo el equipo" en el ticket (a diferencia de Bodega/Servicios) —
        // default false, sin asumir. Ver también memory: "¿ve otras áreas para reportes?" pendiente.
        ['role' => 'lider_admin_contab', 'module' => 'admin_contab', 'view_team' => false,
            'note' => 'view_team sin confirmar explícitamente por el cliente — default conservador'],

        // Asistente Administrativa (Yaneth) — Admin&Contab (alcance exacto vs Felix, pendiente
        // de confirmar). Se siembra la visibilidad del módulo (SCRUM-144 sí la confirma), no el
        // alcance fino.
        ['role' => 'asistente_administrativa', 'module' => 'admin_contab', 'view_team' => false,
            'note' => 'alcance exacto vs Felix pendiente de confirmar (ver memory roles/permisos)'],

        // Líder Compras (Yirena) — Compras.
        ['role' => 'lider_compras', 'module' => 'compras', 'view_team' => false,
            'note' => 'view_team sin confirmar explícitamente por el cliente — default conservador'],

        // Líder Bodega (Esteban) — Bodega, todo el equipo (explícito en el ticket).
        ['role' => 'lider_bodega', 'module' => 'bodega', 'view_team' => true],

        // Asistente de Bodega (Osvaldo, Mariano) — Bodega, todo el equipo (SCRUM-144 lo confirma
        // así; SCRUM-145 marca "Ver: a confirmar" en su propia tabla de permisos, pero esa tabla
        // define capacidad, no visibilidad — se prioriza SCRUM-144 para esta fila, ver nota arriba
        // del array).
        ['role' => 'asistente_bodega', 'module' => 'bodega', 'view_team' => true],

        // Ayudante General de Bodega (Apolonio) — Bodega, alcance "Picking" (subconjunto funcional
        // que este modelo todavía no puede expresar a nivel de módulo — ver ADR-006, no resuelto).
        // Se siembra visibilidad de módulo completo por ahora; el recorte fino queda para Fase D.
        ['role' => 'ayudante_general_bodega', 'module' => 'bodega', 'view_team' => false,
            'note' => 'alcance real es "Picking" únicamente — granularidad de submódulo no modelada en Fase A'],

        // Transporte (Gary) — Bodega, alcance "Planeación de rutas" (mismo caveat que Picking).
        ['role' => 'transporte', 'module' => 'bodega', 'view_team' => false,
            'note' => 'alcance real es "Planeación de rutas" únicamente — no modelado en Fase A'],

        // Líder de Servicios (Araon) — Servicios, todo el equipo (explícito).
        ['role' => 'lider_servicios', 'module' => 'servicios', 'view_team' => true],

        // Técnico de Servicios (3 personas) — Servicios, todo el equipo, sin reportes (el "sin
        // reportes" tampoco es modelable a nivel de módulo en Fase A — Reportes no es un módulo
        // de este catálogo, ver ADR-006 Decisión 6).
        ['role' => 'tecnico_servicios', 'module' => 'servicios', 'view_team' => true],

        // Garantías de Servicios (Miguel) — Servicios, todo el equipo, sin reportes (mismo caveat).
        ['role' => 'garantias_servicios', 'module' => 'servicios', 'view_team' => true],

        // Vendedor/Diseñador (9 personas) — Ventas & Diseño, SOLO su propia cartera.
        // view_team=false es la respuesta confirmada, no un default por ausencia de dato.
        ['role' => 'vendedor_disenador', 'module' => 'ventas_diseno', 'view_team' => false],

        // Líder de Operaciones (Mariam) — Operaciones (cruza Compras+Bodega+Servicios sin módulo
        // propio real — se siembra contra el módulo "operaciones" del catálogo tal cual lo pide
        // el ticket; el cruce funcional con los otros 3 módulos no se modela en Fase A).
        ['role' => 'lider_operaciones', 'module' => 'operaciones', 'view_team' => false,
            'note' => 'view_team sin confirmar explícitamente por el cliente — default conservador'],
    ];

    public function up(): void
    {
        Schema::create($this->table('role_module_visibility'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('module', 40);
            $table->smallInteger('can_view')->default(0);
            $table->boolean('can_view_team')->default(false);
            $table->unique(['role_id', 'module']);
        });

        $roleIds = DB::table($this->table('roles'))->pluck('id', 'key');

        foreach ($this->visibility as $row) {
            $roleId = $roleIds[$row['role']] ?? null;
            if ($roleId === null) {
                throw new \RuntimeException("Seed de role_module_visibility: rol desconocido [{$row['role']}].");
            }

            DB::table($this->table('role_module_visibility'))->insert([
                'role_id'       => $roleId,
                'module'        => $row['module'],
                'can_view'      => 2, // "full" — Fase A no siembra ningún caso de 1=readonly todavía.
                'can_view_team' => $row['view_team'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('role_module_visibility'));
    }
};
