<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Langganan notifikasi HP yang dikirim browser (PushSubscription.toJSON()): alamat push dan dua kunci. */
class LanggananPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $hapus = $this->isMethod('DELETE');

        return [
            'endpoint'     => ['required', 'string', 'max:1000', 'url:https'],
            'keys'         => [$hapus ? 'nullable' : 'required', 'array'],
            'keys.p256dh'  => [$hapus ? 'nullable' : 'required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'keys.auth'    => [$hapus ? 'nullable' : 'required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
        ];
    }
}
