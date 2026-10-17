<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Models;

use DewanLab\LaravelZktecoAdmsCore\Enums\BioType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model storing multimodal biometric templates captured by or provisioned to ZKTeco terminals.
 *
 * Supports diverse biometric modalities including fingerprint, Visible Light facial templates,
 * and palm vein/print templates via the {@see BioType} enum.
 *
 * Enforces a composite unique database index on `(pin, bio_type, template_index)` to ensure
 * idempotent template updates during protocol exchanges (`BIODATA` table pushes and `DATA UPDATE biodata` commands).
 *
 * Example:
 * ```php
 * $templates = ZkBiodata::where('pin', '1001')
 *     ->where('bio_type', BioType::VisibleLightFace)
 *     ->get();
 * ```
 *
 * @property int $id
 * @property string $pin
 * @property BioType $bio_type
 * @property int $template_index
 * @property int $size
 * @property bool $is_valid
 * @property int $duress
 * @property string $template
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ZkUser|null $user
 */
class ZkBiodata extends Model
{
    protected $guarded = [];

    protected $casts = [
        'bio_type' => BioType::class,
        'is_valid' => 'boolean',
        'template_index' => 'integer',
        'size' => 'integer',
    ];

    /**
     * Get the dynamically prefixed table name for the model.
     *
     * Resolves the table prefix from `zkteco-adms-core.table_prefix` config (defaults to `zkteco_biodata`).
     */
    public function getTable(): string
    {
        return config('zkteco-adms-core.table_prefix', 'zkteco_').'biodata';
    }

    /**
     * Get the user associated with this biometric template via PIN.
     *
     * @return BelongsTo<ZkUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ZkUser::class, 'pin', 'pin');
    }
}
