<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InventoryMovement extends Model
{
    public const ORIGIN_LABELS = [
        'manual_in' => 'Kézi bevételezés',
        'manual_out' => 'Kézi kiadás',
        'shipment_dispatch' => 'Fuvarfeladás',
        'shipment_receipt' => 'Fuvarátvétel',
        'return' => 'Visszáru',
        'scrap' => 'Selejtezés',
    ];

    public function originLabel(): string
    {
        $origin = match (true) {
            $this->isShipmentDispatch() => 'shipment_dispatch',
            $this->isShipmentReceipt() => 'shipment_receipt',
            $this->isScrap() => 'scrap',
            $this->isReturn() => 'return',
            $this->type === 'in' => 'manual_in',
            default => 'manual_out',
        };

        return self::ORIGIN_LABELS[$origin];
    }

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'user_id',
        'type',
        'quantity',
        'reference',
        'stock_before',
        'stock_after',
        'partner_id', 'partner_name',
        'return_of_id', 'return_reason',
        'quarantined_quantity', 'scrap_of_id', 'shipment_transit_item_id', 'shipment_receipt_item_id',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'stock_before' => 'integer', 'stock_after' => 'integer',
            'quarantined_quantity' => 'integer', 'shipment_transit_item_id' => 'integer', 'shipment_receipt_item_id' => 'integer'];
    }

    public function getMovementNumberAttribute(): string
    {
        return self::formatNumber((int) $this->id);
    }

    public static function formatNumber(int $id): string { return 'MOZ-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT); }

    public function partner() { return $this->belongsTo(Partner::class); }

    public function returnOf() { return $this->belongsTo(self::class, 'return_of_id'); }

    public function returns() { return $this->hasMany(self::class, 'return_of_id'); }

    public function isReturn(): bool { return $this->return_of_id !== null; }

    public function isScrap(): bool { return $this->scrap_of_id !== null; }
    public function isShipmentDispatch(): bool { return $this->shipment_transit_item_id !== null; }
    public function isShipmentReceipt(): bool { return $this->shipment_receipt_item_id !== null; }
    public function isReturnable(): bool { return !$this->isReturn() && !$this->isScrap() && !$this->isShipmentDispatch() && !$this->isShipmentReceipt(); }
    public function shipmentTransitItem(): BelongsTo { return $this->belongsTo(ShipmentTransitItem::class); }
    public function shipmentReceiptItem(): BelongsTo { return $this->belongsTo(ShipmentReceiptItem::class); }
    public function manualReceiptBatch(): HasOne { return $this->hasOne(ManualReceiptBatch::class, 'movement_id'); }
    public function issueSources(): HasMany { return $this->hasMany(InventoryMovementSource::class, 'movement_id')->orderBy('id'); }
    public function scrapOf() { return $this->belongsTo(self::class, 'scrap_of_id'); }
    public function qualityActions() { return $this->hasMany(ReturnQualityAction::class, 'return_movement_id'); }
    public function qualityAction() { return $this->hasOne(ReturnQualityAction::class, 'movement_id'); }

    public function getOriginalMovementNumberAttribute(): ?string
    {
        return $this->isReturn() ? 'MOZ-'.str_pad((string)$this->return_of_id, 6, '0', STR_PAD_LEFT) : null;
    }

    // Kapcsolat a Termékkel
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    // Kapcsolat a Raktárral
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'warehouse_id');
    }

    // Melyik felhasználó csinálta a tranzakciót
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }



}
