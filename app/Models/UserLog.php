<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserLog extends Model
{
    use HasFactory;
    protected $table = 'user_logs';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['user_id', 'action', 'method', 'route', 'status_code', 'ip_address', 'user_agent', 'details', 'company'];

    protected $appends = ['details_decoded'];

    /**
     * The user that performed the activity. May be null for entries recorded
     * outside an authenticated request (queued jobs, console commands).
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * `details` is stored as a JSON string when it holds structured context and
     * as plain text otherwise. Expose a decoded version for API consumers.
     */
    public function getDetailsDecodedAttribute()
    {
        if (is_null($this->details)) {
            return null;
        }

        $decoded = json_decode($this->details, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $this->details;
    }
}
