<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;

use App\Models\Reception;

class ReceptionVideoController extends Controller
{
    public function show(Video $video)
    {
        return response()->json([
            'id'        => $video->id,
            'title'     => $video->title,
            'file_path' => $video->file_path,
        ]);
    }
    public function videoStatus(Reception $reception)
    {
        $rows = $reception->videoConfirms()->get();

        return response()->json([
            'required_confirmed' => $rows
                ->where('video_type', 'required')
                ->isNotEmpty(),

            'confirmed_videos' => $rows->map(fn($r) => [
                'video_id' => $r->video_id,
                'video_type' => $r->video_type,
                'confirmed_at' => $r->confirmed_at,
            ]),
        ]);
    }

public function videoStatusByToken(string $token)
{
    $reception = Reception::where('token', $token)->firstOrFail();

    $rows = $reception->videoConfirms()->get();

    return response()->json([
        'required_confirmed' => $rows
            ->where('video_type', 'required')
            ->isNotEmpty(),

        'confirmed_videos' => $rows->map(fn ($r) => [
            'video_id' => $r->video_id,
            'video_type' => $r->video_type,
            'confirmed_at' => $r->confirmed_at,
        ]),
    ]);
}
}
