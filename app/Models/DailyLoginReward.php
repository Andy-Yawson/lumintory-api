<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyLoginReward extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'last_reward_date'];
}
