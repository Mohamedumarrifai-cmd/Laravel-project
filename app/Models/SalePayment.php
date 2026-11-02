<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:4',
        'meta_data' => 'array',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}

