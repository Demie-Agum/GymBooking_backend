<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class GymSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'date',
        'start_time',
        'end_time',
        'capacity',
        'image',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Get the bookings for this session.
     */
    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Get confirmed bookings count.
     */
    public function getConfirmedBookingsCountAttribute()
    {
        return $this->bookings()->where('status', 'confirmed')->count();
    }

    /**
     * Check if session is full.
     */
    public function isFull()
    {
        return $this->confirmed_bookings_count >= $this->capacity;
    }

    /**
     * Get available spots.
     */
    public function getAvailableSpotsAttribute()
    {
        return max(0, $this->capacity - $this->confirmed_bookings_count);
    }

    /**
     * Check if session overlaps with another session.
     */
    public function overlapsWith($otherSession)
    {
        // Only check if they're on the same date
        if ($this->date->format('Y-m-d') !== $otherSession->date->format('Y-m-d')) {
            return false;
        }

        $thisStart = Carbon::parse($this->date->format('Y-m-d') . ' ' . $this->start_time);
        $thisEnd = Carbon::parse($this->date->format('Y-m-d') . ' ' . $this->end_time);
        $otherStart = Carbon::parse($otherSession->date->format('Y-m-d') . ' ' . $otherSession->start_time);
        $otherEnd = Carbon::parse($otherSession->date->format('Y-m-d') . ' ' . $otherSession->end_time);

        return $thisStart->lt($otherEnd) && $thisEnd->gt($otherStart);
    }
}

