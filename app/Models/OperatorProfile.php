<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// app/Models/OperatorProfile.php
class OperatorProfile extends Model
{
    protected $fillable = ['user_id', 'state'];

    // 表示ラベル
    public function getStateLabelAttribute(): string
    {
        return [
            'available' => '待機中',
            'busy'      => '接客中',
            'break'     => '休憩中',
            'off_today' => '本日休業',
        ][$this->state] ?? $this->state;
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
