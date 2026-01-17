<?php

// app/Models/Video.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// app/Models/Video.php
class Video extends Model
{
    protected $fillable = [
        'title',
        'description',
        'file_path',
        'public_token',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['public_url'];

    public function getPublicUrlAttribute()
    {
        return url("/videos/watch/{$this->public_token}");
    }
}

