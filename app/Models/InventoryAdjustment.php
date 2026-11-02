<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryAdjustment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $casts = [
        'total_loss_cost' => 'decimal:4',
    ];

    public function items()
    {
        return $this->hasMany(InventoryAdjustmentItem::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}

