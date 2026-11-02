<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'cost_price' => 'decimal:4',
        'selling_price' => 'decimal:4',
        'min_selling_price' => 'decimal:4',
        'is_batch_tracked' => 'boolean',
        'is_taxable' => 'boolean',
        'vat_applicable' => 'boolean',
        'sscl_applicable' => 'boolean',
        'custom_tax_rate' => 'decimal:2',
        'min_stock_threshold' => 'decimal:4',
        'reorder_quantity' => 'decimal:4',
        'is_scale_weight_item' => 'boolean',
        'has_modifiers' => 'boolean',
        'modifiers' => 'array',
        'is_prescription_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function batches()
    {
        return $this->hasMany(Batch::class);
    }

    public function stocks()
    {
        return $this->hasMany(BranchProductStock::class);
    }
}

