<?php

// app/Http/Controllers/OperationController.php
namespace App\Http\Controllers;

use App\Models\User;
use App\Models\OperatorProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class OperationController extends Controller
{
    use AuthorizesRequests;
    
    public function index()
    {
        $this->authorize('manage-operators');

        // 一覧（必要な情報だけ）
        $operators = User::query()
            ->with('operatorProfile:id,user_id,state')
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get()
            ->map(fn($u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'state' => $u->operatorProfile->state ?? 'off_today',
                'label' => optional($u->operatorProfile)->state_label ?? '本日休業',
            ]);

        return Inertia::render('Operation/Operators', [
            'operators' => $operators,
        ]);
    }

    public function updateState(Request $request, User $user)
    {
        $this->authorize('manage-operators');

        $request->validate(['state' => 'required|in:available,busy,break,off_today']);

        $profile = OperatorProfile::firstOrCreate(['user_id' => $user->id]);
        $profile->update(['state' => $request->state]);

        return response()->json(['ok' => true]);
    }
}
