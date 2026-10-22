<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanPenghuniRequest;
use App\Http\Resources\PenghuniResource;
use App\Models\Penghuni;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PenghuniController extends Controller
{
    public function index(): JsonResponse
    {
        $daftar = Penghuni::with('kontrakAktif.kamar')->orderBy('nama')->get();

        return response()->json(['data' => PenghuniResource::collection($daftar)]);
    }

    public function store(SimpanPenghuniRequest $request): JsonResponse
    {
        // Penghuni dan akun login-nya dibuat bersama: penghuni tanpa akun tidak
        // bisa melihat tagihannya sendiri (R-07), jadi keduanya satu kesatuan.
        $penghuni = DB::transaction(function () use ($request) {
            $penghuni = Penghuni::create($request->validated());

            $user = User::create([
                'name' => $penghuni->nama,
                'email' => $penghuni->email,
                'password' => Hash::make($request->string('password')->value()),
                'peran' => User::PERAN_PENGHUNI,
                'penghuni_id' => $penghuni->id,
            ]);

            return $penghuni->setRelation('user', $user);
        });

        return response()->json(['data' => new PenghuniResource($penghuni)], 201);
    }

    public function show(Penghuni $penghuni): JsonResponse
    {
        $penghuni->load(['kontrakAktif.kamar']);

        return response()->json(['data' => new PenghuniResource($penghuni)]);
    }

    public function update(SimpanPenghuniRequest $request, Penghuni $penghuni): JsonResponse
    {
        $penghuni->update($request->validated());

        // Nama dan email di akun login ikut disesuaikan supaya tidak berbeda
        // dari data induknya.
        $penghuni->user?->update([
            'name' => $penghuni->nama,
            'email' => $penghuni->email,
        ]);

        return response()->json(['data' => new PenghuniResource($penghuni)]);
    }
}
