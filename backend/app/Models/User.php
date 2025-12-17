<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
  protected $fillable = [
    'firstname',
    'lastname',
    'middlename',
    'email',
    'password',
    'profile_picture',
    'otp_code',
    'otp_expires_at',
    'verification_token',
    'is_verified',
    'email_verified_at',
    'membership_level_id',
    'subscription_expires_at',
    'role',
];

/**
 * Get the membership level for the user
 */
public function membershipLevel()
{
    return $this->belongsTo(MembershipLevel::class);
}

/**
 * Get the bookings for the user
 */
public function bookings()
{
    return $this->hasMany(Booking::class);
}
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'subscription_expires_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Get the user's full name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        $middlename = $this->middlename ? ' ' . $this->middlename . ' ' : ' ';
        return $this->firstname . $middlename . $this->lastname;
    }

    /**
     * Check if user is super admin
     */
    public function isSuperAdmin()
    {
        return $this->role === 'super_admin';
    }

    /**
     * Check if user is admin
     */
    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is staff
     */
    public function isStaff()
    {
        return $this->role === 'staff';
    }

    /**
     * Check if user is regular user
     */
    public function isUser()
    {
        return $this->role === 'user';
    }

    /**
     * Check if user is admin or staff
     */
    public function isAdminOrStaff()
    {
        return $this->role === 'admin' || $this->role === 'staff';
    }

    /**
     * Check if user is super admin or admin
     */
    public function isSuperAdminOrAdmin()
    {
        return $this->role === 'super_admin' || $this->role === 'admin';
    }

    /**
     * Check if subscription is active (not expired)
     */
    public function isSubscriptionActive()
    {
        // If no expiry date, subscription is active (no expiration)
        if (is_null($this->subscription_expires_at)) {
            return true;
        }
        
        // Check if expiry date is in the future
        return $this->subscription_expires_at->isFuture();
    }

    /**
     * Check if subscription is expiring soon (within 7 days)
     */
    public function isSubscriptionExpiringSoon()
    {
        if (is_null($this->subscription_expires_at)) {
            return false;
        }
        
        $daysUntilExpiry = $this->daysUntilExpiry();
        return $daysUntilExpiry > 0 && $daysUntilExpiry <= 7;
    }

    /**
     * Get days until subscription expires
     */
    public function daysUntilExpiry()
    {
        if (is_null($this->subscription_expires_at)) {
            return null; // No expiry
        }
        
        $now = now();
        $expiry = $this->subscription_expires_at;
        
        if ($expiry->isPast()) {
            return 0; // Already expired
        }
        
        // Return whole number (no decimals) - round down
        $days = $now->diffInDays($expiry, false);
        return (int) floor($days);
    }

    /**
     * Downgrade user to Free membership level
     */
    public function downgradeToFree()
    {
        // Find Free membership level by name
        $freeLevel = MembershipLevel::where('name', 'Free')->first();
        
        if ($freeLevel) {
            $this->membership_level_id = $freeLevel->id;
            // Clear subscription expiry for Free tier
            $this->subscription_expires_at = null;
            $this->save();
            return true;
        }
        
        return false;
    }

    /**
     * Check if subscription is expired and downgrade to Free if needed
     * Returns true if downgraded, false otherwise
     */
    public function checkAndDowngradeIfExpired()
    {
        // Only check for regular users
        if (!$this->isUser()) {
            return false;
        }

        // If no expiry date, nothing to check
        if (is_null($this->subscription_expires_at)) {
            return false;
        }

        // Check if subscription has expired
        if ($this->subscription_expires_at->isPast()) {
            // Check if user is already on Free tier
            if ($this->membershipLevel && $this->membershipLevel->name === 'Free') {
                // Already on Free, just clear expiry if it's set
                if ($this->subscription_expires_at) {
                    $this->subscription_expires_at = null;
                    $this->save();
                }
                return false;
            }
            
            // Downgrade to Free
            return $this->downgradeToFree();
        }

        return false;
    }
}