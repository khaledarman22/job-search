<?php

namespace App\Http\Controllers;

use App\Enums\OutreachStatus;
use App\Models\OutreachEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TrackingController extends Controller
{
    /** GIF شفاف 1×1 (43 بايت) */
    private const PIXEL = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\x00\x00\x00\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    public function __invoke(Request $request, string $token): Response
    {
        $email = OutreachEmail::where('tracking_token', $token)
            ->where('status', OutreachStatus::Sent)
            ->first();

        if ($email) {
            $now = now();
            $email->forceFill([
                'opened_at' => $email->opened_at ?? $now,
                'last_opened_at' => $now,
                'open_count' => $email->open_count + 1,
            ])->save();

            $email->opens()->create([
                'opened_at' => $now,
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            ]);
        }

        // نفس الرد دائمًا — لا فرق بين توكن صحيح وغلط
        return response(self::PIXEL, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
