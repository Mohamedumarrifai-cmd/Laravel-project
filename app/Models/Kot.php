<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Kot extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $casts = [
        'started_cooking_at' => 'datetime',
        'ready_at' => 'datetime',
        'served_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(KotItem::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function table()
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }
}
