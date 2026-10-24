<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Exceptions\AssociateInUseException;
use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1121 — asociados (base `architects`) de un proyecto y de sus cotizaciones. UNA sola fuente de verdad: el proyecto. Una cotización con
 * proyecto lee y escribe los asociados del proyecto (así lo creado en la cotización aparece en la tarjeta de Pipeline y viceversa); una
 * cotización sin proyecto guarda los suyos aparte y los traslada al proyecto en cuanto se le asigna uno (`mergeDraftIntoProject`).
 *
 * `quotes.architect_id` queda fuera: es el arquitecto que origina la comisión de Admin&Cont y no cambia de significado. Elegirlo en la
 * cotización sí lo agrega a los asociados del proyecto (`syncQuoteArchitect`), pero agregar un arquitecto SOLO al proyecto no toca esa columna.
 */
final class ProjectAssociateService
{
    public const COMMISSION_ARCHITECT_MESSAGE = 'Este arquitecto es el «Arquitecto» de una cotización del proyecto (origina la comisión). Cámbielo primero en el campo «Arquitecto».';

    /** @return Collection<int, Architect> */
    public function forProject(SalesProject $project): Collection
    {
        return $project->associates()->get();
    }

    /** @return Collection<int, Architect> asociados que ve la cotización: los de su proyecto, o los de su borrador si aún no tiene proyecto */
    public function forQuote(Quote $quote): Collection
    {
        if ($quote->sales_project_id !== null) {
            $project = SalesProject::find($quote->sales_project_id);

            return $project !== null ? $this->forProject($project) : collect();
        }

        return $quote->draftAssociates()->get();
    }

    public function attachToProject(SalesProject $project, Architect $associate): void
    {
        $project->associates()->syncWithoutDetaching([$associate->id]);
    }

    /**
     * @throws AssociateInUseException si es el «Arquitecto» (el que origina la comisión de Admin&Cont) de alguna cotización del proyecto
     */
    public function detachFromProject(SalesProject $project, int $associateId): void
    {
        $isCommissionArchitect = Quote::query()
            ->where('sales_project_id', $project->id)
            ->where('architect_id', $associateId)
            ->exists();
        if ($isCommissionArchitect) {
            throw new AssociateInUseException(self::COMMISSION_ARCHITECT_MESSAGE);
        }

        $project->associates()->detach($associateId);
    }

    public function attachToQuote(Quote $quote, Architect $associate): void
    {
        if ($quote->sales_project_id !== null) {
            $project = SalesProject::find($quote->sales_project_id);
            if ($project !== null) {
                $this->attachToProject($project, $associate);

                return;
            }
        }

        $quote->draftAssociates()->syncWithoutDetaching([$associate->id]);
    }

    /**
     * @throws AssociateInUseException si es el «Arquitecto» de esta cotización (sin proyecto) o de alguna del proyecto
     */
    public function detachFromQuote(Quote $quote, int $associateId): void
    {
        if ($quote->sales_project_id !== null) {
            $project = SalesProject::find($quote->sales_project_id);
            if ($project !== null) {
                $this->detachFromProject($project, $associateId);

                return;
            }
        }

        if ($quote->architect_id === $associateId) {
            throw new AssociateInUseException(self::COMMISSION_ARCHITECT_MESSAGE);
        }

        $quote->draftAssociates()->detach($associateId);
    }

    /**
     * Al asignarle proyecto a una cotización, sus asociados de borrador pasan al proyecto (sin duplicar) y el pivote de borrador se vacía.
     * Idempotente: sin proyecto o sin asociados de borrador no hace nada.
     */
    public function mergeDraftIntoProject(Quote $quote): void
    {
        if ($quote->sales_project_id === null) {
            return;
        }

        $project = SalesProject::find($quote->sales_project_id);
        if ($project === null) {
            return;
        }

        DB::transaction(function () use ($quote, $project): void {
            $ids = $quote->draftAssociates()->pluck('architects.id')->all();
            if ($ids === []) {
                return;
            }

            $project->associates()->syncWithoutDetaching($ids);
            $quote->draftAssociates()->detach();
        });
    }

    /**
     * El «Arquitecto» de la cotización (el que origina la comisión) también es un asociado del proyecto: si la cotización ya tiene
     * proyecto, se agrega a los del proyecto; si no, a los de su borrador.
     */
    public function syncQuoteArchitect(Quote $quote): void
    {
        if ($quote->architect_id === null) {
            return;
        }

        $architect = Architect::find($quote->architect_id);
        if ($architect !== null) {
            $this->attachToQuote($quote, $architect);
        }
    }

    /** @return array<string, mixed> */
    public static function format(Architect $a): array
    {
        return ['id' => $a->id, 'type' => $a->type, 'name' => $a->name, 'phone' => $a->phone, 'email' => $a->email, 'document' => $a->document];
    }
}
