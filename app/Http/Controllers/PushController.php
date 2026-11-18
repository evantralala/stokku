<?php

namespace App\Http\Controllers;

use App\Http\Requests\LanggananPushRequest;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;

/** Simpan dan hapus langganan notifikasi HP perangkat yang sedang dipakai (dipanggil resources/js/push.js). */
class PushController extends Controller
{
    public function __construct(private readonly PushService $layanan) {}

    public function simpan(LanggananPushRequest $request): JsonResponse
    {
        abort_unless(PushService::aktif(), 404);

        $this->layanan->langgan($request->user(), $request->validated(), $request->userAgent());

        return response()->json(['aktif' => true]);
    }

    public function hapus(LanggananPushRequest $request): JsonResponse
    {
        $this->layanan->berhenti($request->user(), $request->validated('endpoint'));

        return response()->json(['aktif' => false]);
    }
}
