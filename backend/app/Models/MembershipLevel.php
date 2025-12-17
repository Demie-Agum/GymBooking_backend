<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MembershipLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'weekly_limit',
        'priority',
        'default_duration_days',
    ];

    /**
     * Get the users for this membership level.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Check if this level has unlimited bookings.
     */
    public function isUnlimited()
    {
        return is_null($this->weekly_limit);
    }
}



