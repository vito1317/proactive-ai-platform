<?php

namespace App\Pai\Cognition;

use App\Pai\Perception\PaiEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 一次協調者的認知運行軌跡 (L3)。
 *
 * @property int $id
 * @property int $event_id
 * @property string $domain
 * @property string $coordinator
 * @property RunStatus $status
 * @property array $steps
 * @property array $findings
 * @property array $actions
 * @property ?string $summary
 * @property ?string $error
 * @property int $tokens
 */
class AgentRun extends Model
{
    protected $table = 'pai_agent_runs';

    protected $fillable = [
        'event_id', 'domain', 'coordinator', 'status',
        'steps', 'findings', 'actions', 'summary', 'error', 'tokens',
    ];

    protected $casts = [
        'status' => RunStatus::class,
        'steps' => 'array',
        'findings' => 'array',
        'actions' => 'array',
        'tokens' => 'integer',
    ];

    protected $attributes = [
        'steps' => '[]',
        'findings' => '[]',
        'actions' => '[]',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(PaiEvent::class, 'event_id');
    }
}
