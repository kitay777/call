<?php

namespace App\Http\Controllers;

use App\Models\Reception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Support\Carbon;

class ReceptionController extends Controller
{
    public function start()
    {
        return Inertia::render('Reception/Start');
    }

    public function faceUpload(Request $request, string $token)
    {
        $rec = Reception::whereToken($token)->firstOrFail();

        $data = $request->validate([
            'image' => 'required|string',
        ]);

        // Base64 デコード
        $base64 = preg_replace('#^data:image/\w+;base64,#i', '', $data['image']);
        $binary = base64_decode($base64);

        // 保存
        $filename = 'face_' . $rec->id . '_' . time() . '.png';
        $path = "faces/{$filename}";

        \Storage::disk('public')->put($path, $binary);

        // meta に記録
        $meta = $rec->meta ?? [];
        $meta['face_image'] = $path;
        $rec->meta = $meta;
        $rec->save();

        return response()->json([
            'ok'   => true,
            'path' => $path,
            'url'  => asset('storage/' . $path),
        ]);
    }
    public function startPost(Request $request)
    {
        // 受付の新規発行（デモ用に in_progress を直指定するならここで）
        $rec = new Reception();
        $rec->token  = Str::uuid()->toString();
        $rec->status = 'waiting';              // ← 通常は waiting
        // $rec->status = 'in_progress';      // ← デモで直接進めたいならこちらに切替

        // ★ 6桁の部屋番号（重複しない）
        $rec->code   = Reception::generateCode();

        $rec->meta   = [];
        $rec->save();

        // 遷移先は運用に合わせて
        return redirect()->route(
            $rec->status === 'in_progress' ? 'reception.in_progress' : 'reception.waiting',
            $rec->token
        );
    }

    public function waiting(string $token)
    {
        $rec = Reception::whereToken($token)->firstOrFail();
        return Inertia::render('Reception/Waiting', [
            'reception' => $rec,
        ]);
    }

    public function status(string $token)
    {
        $rec = Reception::where('token', $token)->first();

        if (!$rec) {
            return response()->json(['ok' => false, 'message' => 'invalid token'], 404);
        }

        return response()->json([
            'ok'     => true,
            'status' => $rec->status,             // waiting / in_progress / ...
            'meta'   => $rec->meta ?? (object)[], // ここに room_id が入る
        ]);
    }

    public function inProgress(string $token)
    {
        $rec = Reception::whereToken($token)->firstOrFail();

        // ★ room_id を必ず持たせる（code → token の順でフォールバック）
        $meta = $rec->meta ?? [];
        if (empty($meta['room_id'])) {
            $meta['room_id'] = $rec->code ?: $rec->token;
            $rec->meta = $meta;
            $rec->save();
        }

        return Inertia::render('Reception/InProgress', [
            'reception'    => $rec,                          // meta.room_id を含む Model 丸渡し
            'signalingUrl' => config('app.signaling_url'),   // Vue 側の fallback を潰して明示
        ]);
    }

    public function advance(Request $request, string $token)
    {
        $rec  = Reception::whereToken($token)->firstOrFail();
        $next = $request->input('next'); // 'verify' など
        $rec->update(['status' => $next]);
        return response()->json(['ok' => true]);
    }

    public function verify(string $token)
    {
        return Inertia::render('Reception/Verify', ['token' => $token]);
    }
    public function apply(string $token)
    {
        return Inertia::render('Reception/Apply', ['token' => $token]);
    }
    public function important(string $token)
    {
        return Inertia::render('Reception/Important', ['token' => $token]);
    }
    public function sign(string $token)
    {
        return Inertia::render('Reception/Sign', ['token' => $token]);
    }

    // use Illuminate\Support\Facades\Http;

    public function signStore(Request $request, string $token)
    {
        $rec = Reception::whereToken($token)->firstOrFail();
        $img = $request->input('image');
        if ($img) {
            $data = explode(',', $img);
            $decoded = base64_decode($data[1]);
            $path = "signatures/{$rec->token}.png";
            \Storage::disk('public')->put($path, $decoded);
            $rec->meta = array_merge($rec->meta ?? [], ['signature_path' => $path]);
            $rec->save();

            // 🚀 Signalingサーバーへ通知（非同期OK）
            try {
                Http::post(env('SIGNALING_API_URL', 'https://dev.call.navi.jpn.com/api/signature-done'), [
                    'roomId' => $rec->meta['room_id'] ?? $rec->code,
                ]);
            } catch (\Throwable $e) {
                \Log::warning('Failed to notify signaling server: ' . $e->getMessage());
            }
        }

        return response()->json(['ok' => true, 'path' => $path, 'url' => asset('storage/' . $path),]);
    }


    public function done(string $token)
    {
        return Inertia::render('Reception/Done');
    }

    public function notifyVideo(string $token)
    {
        $rec = Reception::whereToken($token)->firstOrFail();
        $meta = $rec->meta ?? [];
        $meta['has_video'] = true;
        $rec->meta = $meta;
        $rec->save();

        return response()->json(['ok' => true]);
    }

    public function waitingList()
    {
        $rows = Reception::query()
            ->whereIn('status', ['waiting', 'in_progress'])
            ->latest()->take(50)->get()
            ->map(fn($r) => [
                'id'         => $r->id,
                'token'      => $r->token,
                'status'     => $r->status,
                'code'       => $r->code,
                'has_video'  => (bool)($r->meta['has_video'] ?? false),
            ]);
        return response()->json($rows);
    }

    /** 既存: token で Client を開く */
    public function videoClient(string $token)
    {
        return Inertia::render('Talk/Client', [
            'token'        => $token,
            'signalingUrl' => config('app.signaling_url'),
        ]);
    }

    /** ★ 追加: 部屋番号(code)で Client を開く（/talk/room/{code} 用） */
    public function videoClientByCode(string $code)
    {
        $rec = \App\Models\Reception::where('code', $code)->firstOrFail();
        return \Inertia\Inertia::render('Talk/Client', [
            'token'        => $rec->token,
            'signalingUrl' => config('app.signaling_url'),
        ]);
    }

    public function heartbeat(string $token)
    {
        $rec = Reception::where('token', $token)->first();
        if (!$rec) {
            return response()->json(['ok' => false], 404);
        }

        // last_seen_at を meta に入れておく（ISO8601）
        $meta = $rec->meta ?? [];
        $meta['last_seen_at'] = now()->toIso8601String();
        $rec->meta = $meta;

        // updated_at も更新（触るだけ）
        $rec->touch();
        $rec->save();

        return response()->json(['ok' => true]);
    }
    public function ackImportant($token)
    {
        $r = Reception::where('token', $token)->firstOrFail();
        $r->status = 'important_ack';
        $r->save();

        return response()->json(['ok' => true]);
    }
}
