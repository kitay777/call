<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reception extends Model
{
    use HasFactory;
    protected $fillable = ['token', 'operator_id', 'status', 'queue_position', 'meta'];
    protected $casts = ['meta' => 'array'];

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
