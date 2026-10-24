<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Jobs\ArchiveAuditLogsJob;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\GlobalSetting;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** SCRUM-812 (REQ-636, Epic SCRUM-788) — archivado a S3 + retención de audit_logs. */
class ArchiveAuditLogsJobTest extends FeatureTestCase
{
    private function activateTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()->makeCurrent();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3-private');
    }

    protected function tearDown(): void
    {
        Tenant::forgetCurrent();
        parent::tearDown();
    }

    private function makeLog(string $action, \Carbon\Carbon $createdAt): AuditLog
    {
        $log = AuditLog::create([
            'action'     => $action,
            'ip_address' => '127.0.0.1',
            'metadata'   => [],
        ]);

        // created_at no está en $fillable (a propósito, ver AuditLog::$fillable) — create()
        // lo ignora en silencio y Eloquent le pone la hora actual. Se pisa después con un
        // UPDATE directo (bypassea updateTimestamps(), que solo toca created_at en el INSERT).
        \Illuminate\Support\Facades\DB::table($log->getTable())
            ->where('id', $log->id)
            ->update(['created_at' => $createdAt]);

        return $log->fresh();
    }

    public function test_archiva_a_s3_y_purga_filas_mas_viejas_que_la_retencion(): void
    {
        $this->activateTenant();
        $old = $this->makeLog(AuditService::AUTH_LOGIN, now()->subMonths(13));

        (new ArchiveAuditLogsJob('atlantic'))->handle();

        $this->activateTenant();
        $this->assertNull(AuditLog::find($old->id), 'La fila vieja debe purgarse de Postgres.');

        $files = collect(Storage::disk('s3-private')->allFiles('audit-logs-archive/atlantic'));
        $this->assertCount(1, $files, 'Debe haber exactamente 1 archivo de archivado.');

        $content = json_decode(Storage::disk('s3-private')->get($files->first()), true);
        $this->assertCount(1, $content);
        $this->assertSame($old->id, $content[0]['id']);
    }

    public function test_no_toca_filas_dentro_de_la_retencion(): void
    {
        $this->activateTenant();
        $recent = $this->makeLog(AuditService::AUTH_LOGIN, now()->subMonths(6));

        (new ArchiveAuditLogsJob('atlantic'))->handle();

        $this->activateTenant();
        $this->assertNotNull(AuditLog::find($recent->id), 'Una fila dentro de la retención no se toca.');
        $this->assertCount(0, Storage::disk('s3-private')->allFiles('audit-logs-archive/atlantic'));
    }

    public function test_retencion_configurable_via_global_setting_no_hardcodeada(): void
    {
        GlobalSetting::query()->updateOrCreate(['key' => 'audit_logs.retention_months'], ['value' => '1']);

        $this->activateTenant();
        $log = $this->makeLog(AuditService::AUTH_LOGIN, now()->subMonths(2));

        (new ArchiveAuditLogsJob('atlantic'))->handle();

        $this->activateTenant();
        $this->assertNull(AuditLog::find($log->id), 'Con retención de 1 mes, una fila de 2 meses debe purgarse.');
    }
}
