<?php

namespace App\Http\Controllers;

use App\Models\OperatorProfile;
use App\Models\Reception;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OperatorController extends Controller
{
    public function dashboard()
    {
        $user = auth()->user();
        $profile = OperatorProfile::firstOrCreate(['user_id' => $user->id], ['state' => 'off_today']);

        $labels = [
            'available' => '待機中',
            'busy'      => '接客中',
            'break'     => '休憩中',
            'off_today' => '本日休業',
        ];

        return Inertia::render('Operator/Dashboard', [
            'profile' => ['state' => $profile->state, 'label' => $labels[$profile->state] ?? $profile->state],
        ]);
    }

    // app/Http/Controllers/OperatorController.php
    public function updateSelfState(Request $request)
    {
        $data = $request->validate([
            'state' => 'required|in:available,busy,break,off_today',
        ]);
        $profile = \App\Models\OperatorProfile::firstOrCreate(['user_id' => auth()->id()]);
        $profile->update(['state' => $data['state']]);  
        return back();
    }


    public function assign(Request $request)
    {
        $rec = Reception::findOrFail($request->input('reception_id'));
        $rec->operator_id = auth()->id();
        $rec->status = 'in_progress';
        $rec->save();
        return response()->json(['ok' => true]);
    }
}
