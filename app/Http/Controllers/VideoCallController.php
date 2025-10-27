<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Reception;            // ← 追加
use App\Events\CallRequested;        // ← 追加
use App\Events\CallAccepted;         // ← 追加

class VideoCallController extends Controller
{
    /**
     * 発信要求（iPad 側）
     */
    public function request(Request $req, string $token)
    {
        $rec = Reception::where('token', $token)->first();

        if (!$rec) {
            return response()->json(['ok' => false, 'message' => 'invalid token'], 404);
        }
        if (!in_array($rec->status, ['waiting', 'in_progress'])) {
            return response()->json(['ok' => false, 'message' => 'status not allowed'], 409);
        }

        broadcast(new CallRequested($token, now()->timestamp));
        return response()->json(['ok' => true]);
    }

    /**
     * 受ける（オペ側）→ roomId を払い出し（または再利用）
     */
    public function accept(Request $req, string $token)
    {
        $rec = Reception::where('token', $token)->first();

        if (!$rec) {
            return response()->json(['ok' => false, 'message' => 'invalid token'], 404);
        }
        if (!in_array($rec->status, ['waiting', 'in_progress'])) {
            return response()->json(['ok' => false, 'message' => 'status not allowed'], 409);
        }

        $meta = $rec->meta ?? [];
        $roomId = $meta['room_id'] ?? Str::uuid()->toString();
        $meta['room_id'] = $roomId;

        // 状態遷移（任意）
        if ($rec->status === 'waiting') {
            $rec->status = 'in_progress';
        }
        $rec->meta = $meta;
        $rec->save();

        // ★ 未ログインでも動くように安全に operatorId を決める
        $operatorId = optional($req->user())->id ?? 'operator';

        broadcast(new CallAccepted($roomId, (string) $operatorId));

        return response()->json(['ok' => true, 'roomId' => $roomId]);
    }

    public function stop(Request $req, string $token)
    {
        // 必要なら受付の状態を戻す等
        return response()->json(['ok' => true]);
    }
}
