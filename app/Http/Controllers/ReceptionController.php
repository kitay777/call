<?php

namespace App\Http\Controllers;

use App\Models\Reception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;


class ReceptionController extends Controller
{
    public function start()
    {
        return Inertia::render('Reception/Start');
    }

    public function startPost(Request $request)
    {
        $rec = Reception::create([
            'token' => Str::uuid()->toString(),
            'status' => 'waiting',
        ]);
        return redirect()->route('reception.waiting', $rec->token);
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
        $rec = Reception::whereToken($token)->firstOrFail();

        // 待機人数（自分含む）
        $waitingCount = Reception::where('status', 'waiting')->count();

        // 推定待ち時間（分）: 1人あたり5分想定（暫定ロジック）
        $etaMinutes = max(0, $waitingCount * 5);

        return response()->json([
            'status'       => $rec->status,
            'waitingCount' => $waitingCount,
            'etaMinutes'   => $etaMinutes,
        ]);
    }

    public function inProgress(string $token)
    {
        $rec = Reception::whereToken($token)->firstOrFail();
        return Inertia::render('Reception/InProgress', ['reception' => $rec]);
    }

    public function advance(Request $request, string $token)
    {
        $rec = Reception::whereToken($token)->firstOrFail();
        $next = $request->input('next'); // verify/apply/important/sign/done
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

    public function signStore(Request $request, string $token)
    {
        // CanvasデータURLを受け取り、画像保存 → signatures に記録
        // ここは本実装時に追加
        return redirect()->route('reception.done', $token);
    }

    public function done(string $token)
    {
        return Inertia::render('Reception/Done');
    }
}
