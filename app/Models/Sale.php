<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'subtotal' => 'decimal:4',
        'discount_rate' => 'decimal:2',
        'discount_amount' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'vat_amount' => 'decimal:4',
        'sscl_amount' => 'decimal:4',
        'service_charge_amount' => 'decimal:4',
        'grand_total' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'gross_profit' => 'decimal:4',
        'paid_amount' => 'decimal:4',
        'change_amount' => 'decimal:4',
        'points_earned' => 'integer',
        'points_redeemed' => 'integer',
        'points_discount_amount' => 'decimal:4',
        'is_synced_from_offline' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function restaurantTable()
    {
        return $this->belongsTo(RestaurantTable::class);
    }

    public function kots()
    {
        return $this->hasMany(Kot::class);
    }
}

