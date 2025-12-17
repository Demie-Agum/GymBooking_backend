<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'contact_name',
        'contact_number',
        'contact_picture',
    ];

    /**
     * Get the user that owns the contact
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}