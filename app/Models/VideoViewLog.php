<?php

// app/Models/VideoViewLog.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoViewLog extends Model
{
    protected $fillable = [
        'reception_id',
        'video_id',
        'viewed_at',
    ];
}

