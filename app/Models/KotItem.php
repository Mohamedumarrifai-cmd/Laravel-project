<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KotItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:4',
        'modifiers' => 'array',
    ];

    public function kot()
    {
        return $this->belongsTo(Kot::class);
    }
}
