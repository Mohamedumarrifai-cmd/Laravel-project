<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RestaurantTable extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];
}

