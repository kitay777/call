<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Video;

class VideoController extends Controller
{
    public function show(string $publicToken)
    {
        $video = Video::where('public_token', $publicToken)
            ->where('is_active', 1)
            ->firstOrFail();

        return response()->json([
            'id' => $video->id,
            'title' => $video->title,
            'description' => $video->description,
            'url' => asset('storage/' . $video->file_path),
        ]);
    }

    public function confirm(Request $request, int $id)
    {
        VideoConfirm::create([
            'reception_id' => $request->input('reception_id'),
            'video_id'     => $id,
            'operator_id'  => auth()->id(),
            'confirmed_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
