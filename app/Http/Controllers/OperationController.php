<?php

// app/Http/Controllers/OperationController.php
namespace App\Http\Controllers;

use App\Models\Reception;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class OperationController extends Controller
{
    public function index()
    {
        Gate::authorize('manage-operators');

        $ops = User::with('operatorProfile:id,user_id,state')
            ->select('id', 'name', 'email')->orderBy('name')->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'state' => $u->operatorProfile->state ?? 'off_today'
            ]);

        $counts = [
            'available' => $ops->where('state', 'available')->count(),
            'busy'      => $ops->where('state', 'busy')->count(),
            'break'     => $ops->where('state', 'break')->count(),
            'off_today' => $ops->where('state', 'off_today')->count(),
            'total'     => $ops->count(),
        ];

        return Inertia::render('Operation/Operators', [
            'operators' => $ops,
            'counts'    => $counts,
        ]);
    }

    // ●用の待機中受付（映像フラグ付き）を返す
    public function waitingList()
    {
        Gate::authorize('manage-operators');

        $rows = Reception::query()
            ->whereIn('status', ['waiting', 'in_progress'])
            ->latest()->take(50)->get()
            ->map(function($r) {
                $isAlive = $r->updated_at->gt(now()->subSeconds(10)); // 直近10秒以内なら生きてると判定
                return [
                    'id'        => $r->id,
                    'token'     => $r->token,
                    'status'    => $r->status,
                    'has_video' => (bool)($r->meta['has_video'] ?? false),
                    'alive'     => $isAlive,
                ];
            });

        return response()->json($rows);
    }

}
