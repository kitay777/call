<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reception extends Model
{
    use HasFactory;
    protected $fillable = ['token', 'operator_id', 'status', 'queue_position','code', 'meta'];
    protected $casts = ['meta' => 'array'];
    /** 6桁の重複しない部屋番号を払い出し */
    public static function generateCode(): string
    {
        do {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (self::where('code', $code)->exists());
        return $code;
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
    
}
