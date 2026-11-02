<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'default_features' => 'array',
        'feature_overrides' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'default_vat_rate' => 'decimal:2',
        'default_sscl_rate' => 'decimal:2',
        'manager_pin_discount_threshold' => 'decimal:2',
    ];

    public function businessType()
    {
        return $this->belongsTo(BusinessType::class);
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}

