<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $pipeline_card_id
 * @property string      $name
 * @property string      $role
 * @property string|null $phone
 * @property string|null $email
 */
class PipelineCardContact extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['pipeline_card_id', 'name', 'role', 'phone', 'email'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'pipeline_card_contacts';
        }

        return "{$tenant->slug}_ventas_diseno.pipeline_card_contacts";
    }

    /** @return BelongsTo<PipelineCard, $this> */
    public function pipelineCard(): BelongsTo
    {
        return $this->belongsTo(PipelineCard::class);
    }
}
