<?php

namespace App\Http\Controllers;

use App\Models\OperatorProfile;
use App\Models\Reception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class OperatorController extends Controller
{
    public function dashboard()
    {
        $user = auth()->user();
        $profile = OperatorProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['state' => 'off_today']
        );

        $labels = [
            'available' => '待機中',
            'busy'      => '接客中',
            'break'     => '休憩中',
            'off_today' => '本日休業',
        ];
        return Inertia::render('Operator/Dashboard', [
            'me'      => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'profile' => ['state' => $profile->state, 'label' => $labels[$profile->state] ?? $profile->state],
        ]);
    }

    public function updateSelfState(Request $request)
    {
        $data = $request->validate([
            'state' => 'required|in:available,busy,break,off_today',
        ]);

        $profile = OperatorProfile::firstOrCreate(['user_id' => auth()->id()]);
        $profile->update(['state' => $data['state']]);

        return to_route('operator.dashboard');
    }

    public function assign(Request $request)
    {
        $rec = Reception::findOrFail($request->input('reception_id'));
        $rec->operator_id = auth()->id();
        $rec->status = 'in_progress';
        $rec->save();
        return response()->json(['ok' => true]);
    }


    public function session(string $token)
    {
        $rec = Reception::whereToken($token)->firstOrFail();

        // ★ room_id を Reception 側と同一ルールで強制
        $meta = $rec->meta ?? [];
        if (empty($meta['room_id'])) {
            $meta['room_id'] = $rec->code ?: $rec->token;
            $rec->meta = $meta;
            $rec->save();
        }
        $roomId = $meta['room_id'];

        return Inertia::render('Operator/Session', [
            'reception' => [
                'token'     => $rec->token,
                'status'    => $rec->status,
                'has_video' => (bool)($meta['has_video'] ?? false),
                'code'      => $rec->code,
                // ★ Vue 側でroomId計算させず、直で渡す（ズレを物理的に排除）
                'meta'      => ['room_id' => $roomId],
            ],
            'signalingUrl' => config('app.signaling_url'),
        ]);
    }


    /**
     * /talk/ops/{id}
     * - 6桁数字: 部屋番号(code)として受付を引く → roomId を払い出して Ops へ渡す
     * - 36桁のUUIDっぽければ roomId としてそのまま渡す
     */
    public function videoOps(string $id)
    {
        // 6桁の部屋番号（先頭ゼロを保持）
        if (preg_match('/^\d{6}$/', $id)) {
            $rec = Reception::where('code', $id)
                ->whereIn('status', ['waiting', 'in_progress'])
                ->firstOrFail();

            $meta   = $rec->meta ?? [];
            $roomId = $meta['room_id'] ?? Str::uuid()->toString();
            $meta['room_id'] = $roomId;

            // 運用: waiting → in_progress へ進める（不要なら削除）
            if ($rec->status === 'waiting') {
                $rec->status = 'in_progress';
            }
            $rec->meta = $meta;
            $rec->save();

            return Inertia::render('Talk/Ops', [
                'roomId'       => $roomId,
                'signalingUrl' => config('app.signaling_url'),
            ]);
        }

        // UUID (簡易判定)
        if ($this->looksLikeUuid($id)) {
            return Inertia::render('Talk/Ops', [
                'roomId'       => $id,
                'signalingUrl' => config('app.signaling_url'),
            ]);
        }

        abort(404);
    }

    /** UUIDっぽいかの簡易チェック */
    private function looksLikeUuid(string $v): bool
    {
        return (bool)preg_match('/^[0-9a-fA-F-]{36}$/', $v);
    }
}
