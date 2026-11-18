<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseTransferAllocation extends Model
{
    public const SOURCE_LOCATION = 'location';
    public const SOURCE_UNASSIGNED = 'unassigned';

    public $timestamps = false;

    protected $fillable = [
        'warehouse_transfer_task_id', 'transfer_workflow_event_id', 'warehouse_location_id', 'source_placement_id',
        'location_code', 'location_name', 'quantity', 'source_type', 'cargo_weight_grams', 'pallet_slots',
        'performed_by', 'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'warehouse_transfer_task_id' => 'integer', 'transfer_workflow_event_id' => 'integer',
            'warehouse_location_id' => 'integer', 'source_placement_id' => 'integer', 'quantity' => 'integer',
            'cargo_weight_grams' => 'integer', 'pallet_slots' => 'integer',
            'performed_by' => 'integer', 'performed_at' => 'immutable_datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(WarehouseTransferTask::class, 'warehouse_transfer_task_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(TransferWorkflowEvent::class, 'transfer_workflow_event_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
