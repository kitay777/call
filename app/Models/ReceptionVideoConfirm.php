<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceptionVideoConfirm extends Model
{
    protected $fillable = [
        'reception_id',
        'video_id',
        'video_type',
        'confirmed_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
    ];

    public function reception()
    {
        return $this->belongsTo(Reception::class);
    }

    public function video()
    {
        return $this->belongsTo(Video::class);
    }

    public function videoConfirms()
    {
        return $this->hasMany(ReceptionVideoConfirm::class);
    }
}
