<?php

namespace App\Http\Controllers\Operation;

use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoController extends Controller
{

    public function index()
    {
        return response()->json(
            Video::orderByDesc('created_at')->get()
        );
    }
    public function activate(Video $video)
    {
        DB::transaction(function () use ($video) {
            Video::where('is_active', true)->update(['is_active' => false]);
            $video->update(['is_active' => true]);
        });

        return response()->json(['success' => true]);
    }
    public function deactivate(Video $video)
    {
        $video->update(['is_active' => false]);

        return response()->json(['success' => true]);
    }

    /**
     * 動画登録（常に1件だけ有効）
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'video' => 'required|file|mimes:mp4,webm|max:512000', // 500MB
        ]);

        DB::beginTransaction();

        try {
            // ① 既存の有効動画を無効化
            Video::where('is_active', true)->update(['is_active' => false]);

            // ② ファイル保存
            $path = $request->file('video')->store('videos', 'public');

            // ③ 新動画を有効として登録
            $video = Video::create([
                'title'        => $request->title,
                'description'  => $request->description,
                'file_path'    => $path,
                'public_token' => Str::uuid(),
                'is_active'    => true,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'video'   => $video,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => '動画登録に失敗しました',
            ], 500);
        }
    }

    /**
     * 現在有効な動画を取得（ユーザー側）
     */
    public function active()
    {
        $video = Video::where('is_active', true)->latest()->first();

        if (!$video) {
            return response()->json(null);
        }

        return response()->json($video);
    }

    /**
     * 公開視聴
     */
    public function watch($token)
    {
        $video = Video::where('public_token', $token)
            ->where('is_active', true)
            ->firstOrFail();

        return view('videos.watch', compact('video'));
    }
    public function list()
    {
        return Video::where('is_active', 1)
            ->orderBy('id')
            ->get(['id', 'title']);
    }
}
