<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\Auth\Models\SystemSetting;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int             $id
 * @property string          $token_hash
 * @property int              $document_id
 * @property int              $created_by
 * @property \Carbon\Carbon  $expires_at
 * @property \Carbon\Carbon|null $revoked_at
 * @property \Carbon\Carbon|null $last_accessed_at
 * @property int              $access_count
 * @property \Carbon\Carbon  $created_at
 * @property ProjectDocument $document
 * @property User            $creator
 */
class ShareLink extends Model
{
    public const UPDATED_AT = null;

    private const DEFAULT_EXPIRATION_DAYS = 30;
    private const TOKEN_BYTES             = 32;

    /**
     * Raw (unhashed) token — only ever populated in-memory, right after createFor()
     * generates it. Never persisted (only token_hash is stored), so it is null for
     * any ShareLink loaded back from the database. Same one-time-visible convention
     * as PasswordResetService: the raw value exists only long enough to build the
     * response URL at creation time.
     */
    public ?string $rawToken = null;

    /** @var list<string> */
    protected $fillable = [
        'token_hash',
        'document_id',
        'created_by',
        'expires_at',
        'revoked_at',
        'last_accessed_at',
        'access_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at'       => 'datetime',
            'revoked_at'       => 'datetime',
            'last_accessed_at' => 'datetime',
            'access_count'     => 'integer',
            'created_at'       => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return 'share_links';
        }

        return "{$tenant->slug}_crm.share_links";
    }

    /** @return BelongsTo<ProjectDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ProjectDocument::class, 'document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * Creates a share link with a random raw token (kept only on the returned
     * instance's rawToken property, for the API to build the one-time share URL)
     * and an expiration computed from the system-wide `share_links_expiration_days`
     * setting. Only the SHA-256 hash of the token is persisted — same convention
     * as PasswordResetService — so the link's URL can never be recovered again
     * after this call returns; the caller must show/copy it now.
     */
    public static function createFor(ProjectDocument $document, User $creator): self
    {
        $setting        = SystemSetting::find('share_links_expiration_days');
        $expirationDays = $setting !== null ? (int) $setting->value : self::DEFAULT_EXPIRATION_DAYS;

        $rawToken = bin2hex(random_bytes(self::TOKEN_BYTES));

        $link = self::create([
            'token_hash'  => self::hashToken($rawToken),
            'document_id' => $document->id,
            'created_by'  => $creator->id,
            'expires_at'  => now()->addDays($expirationDays),
        ]);

        $link->rawToken = $rawToken;

        return $link;
    }

    /** Looks up an active-or-not ShareLink by its raw (unhashed) public token. */
    public static function findByRawToken(string $rawToken): ?self
    {
        /** @var self|null */
        return self::where('token_hash', self::hashToken($rawToken))->first();
    }

    private static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
