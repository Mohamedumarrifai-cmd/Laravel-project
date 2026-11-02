<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'loyalty_points' => 'integer',
        'credit_limit' => 'decimal:2',
        'current_credit_balance' => 'decimal:2',
        'total_spent' => 'decimal:2',
        'allow_store_credit' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function ledgers()
    {
        return $this->hasMany(CustomerLedger::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }
}

