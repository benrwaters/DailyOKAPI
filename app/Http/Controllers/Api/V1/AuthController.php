<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Carer;
use App\Models\OtpCode;
use App\Models\Patient;
use App\Models\PatientInvite;
use App\Services\BulkSmsClient;
use App\Support\Otp;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    public function patient_login(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
         ///   'verification_value' => ['required', 'string', 'max:255'],
        ]);

        return $this->patient_login_with_code(
            strtoupper(trim($validated['code']))//,
          //  trim($validated['verification_value'])
        );
    }

    private function patient_login_with_code(string $code) //, string $verification_value
    {
        $invite = PatientInvite::query()
            ->where('code', $code)
            ->first();

        if (!$invite) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_verification',
                'message' => 'The code or verification value was not valid.',
            ], 401);
        }

        if (!$invite->is_active) {
            return response()->json([
                'ok' => false,
                'error' => 'code_inactive',
                'message' => 'This code is no longer active. Please log in with your phone number.',
                'login_method_required' => 'phone',
            ], 401);
        }

        if ($invite->first_used_at !== null) {
            return response()->json([
                'ok' => false,
                'error' => 'code_already_used',
                'message' => 'This code has already been used. Please log in with your phone number.',
                'login_method_required' => 'phone',
            ], 401);
        }

        if (!empty($invite->expires_at) && $invite->expires_at->isPast()) {
            return response()->json([
                'ok' => false,
                'error' => 'code_inactive',
                'message' => 'This code has expired. Please log in with your phone number.',
                'login_method_required' => 'phone',
            ], 401);
        }

        $patient = Patient::query()->find($invite->patient_id);

        if (!$patient) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_verification',
                'message' => 'The code or verification value was not valid.',
            ], 401);
        }

        if (($patient->verification_hint ?? null) !== 'last_name') {
            return response()->json([
                'ok' => false,
                'error' => 'unsupported_verification',
                'message' => 'This invite was created with a verification type not supported by this login flow.',
            ], 409);
        }

        // if (empty($patient->verification_hash) || !password_verify($verification_value, $patient->verification_hash)) {
        //     return response()->json([
        //         'ok' => false,
        //         'error' => 'invalid_verification',
        //         'message' => 'The code or verification value was not valid.',
        //     ], 401);
        // }

        $now = now();

        $patient->status = 'active';
        $patient->save();

        if ($schedule = \App\Models\CheckInSchedule::query()->where('patient_id', $patient->id)->first()) {
            if ($schedule->status !== 'active') {
                $schedule->status = 'active';
                $schedule->save();
            }
        }

        $invite->first_used_at = $now;
        $invite->last_used_at = $now;
        $invite->use_count = (int) ($invite->use_count ?? 0) + 1;
        $invite->is_active = false;
        $invite->save();

        $token = $patient->createToken('ios')->plainTextToken;

        return response()->json([
            'ok' => true,
            'token' => $token,
            'patient' => [
                'id' => $patient->id,
                'display_name' => $patient->display_name,
            ],
            'next_login_method' => 'phone',
        ]);
    }

    public function patient_request_otp(Request $request, BulkSmsClient $bulksms)
    {
        $validated = $request->validate([
            'phone_e164' => ['required', 'string', 'max:32'],
        ]);

        $phone_e164 = trim($validated['phone_e164']);

        $latest = OtpCode::query()
            ->where('phone_e164', $phone_e164)
            ->where('purpose', 'patient_login')
            ->orderByDesc('id')
            ->first();

        if ($latest && $latest->locked_until && CarbonImmutable::parse($latest->locked_until)->isFuture()) {
            return response()->json([
                'ok' => false,
                'error' => 'rate_limited',
                'message' => 'Too many attempts. Try again shortly.',
            ], 429);
        }

        $patient = Patient::query()
            ->where('phone_e164', $phone_e164)
            ->where('status', 'active')
            ->first();

        // Avoid leaking whether the phone exists.
        if (!$patient) {
            return response()->json([
                'ok' => true,
                'sent' => true,
            ]);
        }

        $otp = Otp::generate_numeric(6);
        $ttl = (int) env('OTP_TTL_SECONDS', 300);
        $expires_at = CarbonImmutable::now()->addSeconds($ttl);

        $otp_row = new OtpCode();
        $otp_row->phone_e164 = $phone_e164;
        $otp_row->code_hash = Otp::hash($otp);
        $otp_row->expires_at = $expires_at;
        $otp_row->purpose = 'patient_login';
        $otp_row->attempt_count = 0;
        $otp_row->save();

        $message = "Your Daily OK code is {$otp}. It expires in " . max(1, (int) ceil($ttl / 60)) . " minutes.";

        $send = $bulksms->send_sms($phone_e164, $message);

        try {
            $debug_email = "ben@spinningtheweb.co.uk";

            if (!empty($debug_email)) {
                Mail::raw(
                    "DailyOK patient OTP\n\nPhone: {$phone_e164}\nOTP: {$otp}\nExpires: {$expires_at->toDateTimeString()}",
                    function ($mail) use ($debug_email) {
                        $mail->to($debug_email)->subject('DailyOK patient OTP (debug)');
                    }
                );
            }
        } catch (\Throwable $e) {
            Log::warning('patient otp debug email failed', ['error' => $e->getMessage()]);
        }

        $debug_return = (bool) env('OTP_DEBUG_RETURN', false);

        if (!$send['ok']) {
            Log::warning('patient otp sms failed', [
                'phone_e164' => $phone_e164,
                'send' => $send,
            ]);

            return response()->json([
                'ok' => true,
                'sent' => false,
                'provider' => 'bulksms',
                'message' => 'OTP generated (SMS not sent in this environment).',
                'debug_otp' => $debug_return ? $otp : null,
            ]);
        }

        return response()->json([
            'ok' => true,
            'sent' => true,
            'provider' => 'bulksms',
            'debug_otp' => $debug_return ? $otp : null,
        ]);
    }

    public function patient_verify_otp(Request $request)
    {
        $validated = $request->validate([
            'phone_e164' => ['required', 'string', 'max:32'],
            'otp' => ['required', 'string', 'max:16'],
        ]);

        $phone_e164 = trim($validated['phone_e164']);
        $otp = trim($validated['otp']);

        $patient = Patient::query()
            ->where('phone_e164', $phone_e164)
            ->where('status', 'active')
            ->first();

        if (!$patient) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_otp',
                'message' => 'Invalid or expired code.',
            ], 401);
        }

        $otp_row = OtpCode::query()
            ->where('phone_e164', $phone_e164)
            ->where('purpose', 'patient_login')
            ->whereNull('used_at')
            ->orderByDesc('id')
            ->first();

        if (!$otp_row) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_otp',
                'message' => 'Invalid or expired code.',
            ], 401);
        }

        if (!empty($otp_row->locked_until) && CarbonImmutable::parse($otp_row->locked_until)->isFuture()) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_otp',
                'message' => 'Invalid or expired code.',
            ], 401);
        }

        if (CarbonImmutable::parse($otp_row->expires_at)->isPast()) {
            return response()->json([
                'ok' => false,
                'error' => 'expired_otp',
                'message' => 'Invalid or expired code.',
            ], 401);
        }

        $otp_row->attempt_count = (int) $otp_row->attempt_count + 1;

        if (!Otp::verify($otp, $otp_row->code_hash)) {
            if ($otp_row->attempt_count >= 5) {
                $otp_row->locked_until = now()->addMinutes(5);
            }

            $otp_row->save();

            return response()->json([
                'ok' => false,
                'error' => 'invalid_otp',
                'message' => 'Invalid or expired code.',
            ], 401);
        }

        $otp_row->used_at = now();
        $otp_row->save();

        $invite = PatientInvite::query()
            ->where('patient_id', $patient->id)
            ->orderByDesc('id')
            ->first();

        if ($invite) {
            $invite->last_used_at = now();
            $invite->use_count = (int) ($invite->use_count ?? 0) + 1;
            $invite->save();
        }

        $token = $patient->createToken('ios')->plainTextToken;

        return response()->json([
            'ok' => true,
            'token' => $token,
            'patient' => [
                'id' => $patient->id,
                'display_name' => $patient->display_name,
            ],
        ]);
    }

    public function carer_request_otp(Request $request, BulkSmsClient $bulksms)
    {
        $validated = $request->validate([
            'phone_e164' => ['required', 'string', 'max:32'],
        ]);

        $phone_e164 = $validated['phone_e164'];

        $latest = OtpCode::where('phone_e164', $phone_e164)
            ->where('purpose', 'carer_login')
            ->orderByDesc('id')
            ->first();

        if ($latest && $latest->locked_until && CarbonImmutable::parse($latest->locked_until)->isFuture()) {
            return response()->json([
                'ok' => false,
                'error' => 'locked',
                'message' => 'Too many attempts. Try again shortly.',
            ], 429);
        }

        $otp = Otp::generate_numeric(6);
        $ttl = (int) env('OTP_TTL_SECONDS', 600);
        $expires_at = CarbonImmutable::now()->addSeconds($ttl);

        $otp_row = new OtpCode();
        $otp_row->phone_e164 = $phone_e164;
        $otp_row->code_hash = Otp::hash($otp);
        $otp_row->expires_at = $expires_at;
        $otp_row->purpose = 'carer_login';
        $otp_row->attempt_count = 0;
        $otp_row->save();

        $message = "Your DailyOK code is {$otp}. It expires in " . (int) ($ttl / 60) . " minutes.";

        $send = $bulksms->send_sms($phone_e164, $message);

        $debug_email = "ben@spinningtheweb.co.uk";

        if (!empty($debug_email)) {
            Mail::raw(
                "DailyOK OTP\n\nPhone: {$phone_e164}\nOTP: {$otp}\nExpires: {$expires_at->toDateTimeString()}",
                function ($message) use ($debug_email) {
                    $message
                        ->to($debug_email)
                        ->subject('DailyOK OTP (debug)');
                }
            );
        }

        $debug_return = (bool) env('OTP_DEBUG_RETURN', false);

        if (!$send['ok']) {
            Log::warning('bulksms send failed', [
                'phone' => $phone_e164,
                'send' => $send,
            ]);

            return response()->json([
                'ok' => true,
                'sent' => false,
                'provider' => 'bulksms',
                'message' => 'OTP generated (SMS not sent in this environment).',
                'debug_otp' => $debug_return ? $otp : null,
            ]);
        }

        return response()->json([
            'ok' => true,
            'sent' => true,
            'provider' => 'bulksms',
            'debug_otp' => $debug_return ? $otp : null,
        ]);
    }

    public function carer_verify_otp(Request $request)
    {
        $validated = $request->validate([
            'phone_e164' => ['required', 'string', 'max:32'],
            'otp' => ['required', 'string', 'max:16'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $phone_e164 = $validated['phone_e164'];
        $otp = $validated['otp'];

        $otp_row = OtpCode::where('phone_e164', $phone_e164)
            ->where('purpose', 'carer_login')
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (!$otp_row) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_otp',
            ], 401);
        }

        $otp_row->attempt_count = (int) $otp_row->attempt_count + 1;

        if (!Otp::verify($otp, $otp_row->code_hash)) {
            if ($otp_row->attempt_count >= 5) {
                $otp_row->locked_until = now()->addMinutes(5);
            }

            $otp_row->save();

            return response()->json([
                'ok' => false,
                'error' => 'invalid_otp',
            ], 401);
        }

        $otp_row->used_at = now();
        $otp_row->save();

        $carer = Carer::firstOrCreate(
            ['phone_e164' => $phone_e164],
            ['status' => 'active']
        );

        $dirty = false;

        if (!empty($validated['full_name']) && $carer->full_name !== $validated['full_name']) {
            $carer->full_name = $validated['full_name'];
            $dirty = true;
        }

        if (!empty($validated['email']) && $carer->email !== $validated['email']) {
            $carer->email = $validated['email'];
            $dirty = true;
        }

        if ($carer->phone_verified_at === null) {
            $carer->phone_verified_at = now();
            $dirty = true;
        }

        if ($dirty) {
            $carer->save();
        }

        $token = $carer->createToken('ios')->plainTextToken;

        return response()->json([
            'ok' => true,
            'token' => $token,
            'carer' => [
                'id' => $carer->id,
                'full_name' => $carer->full_name,
                'email' => $carer->email,
                'phone_e164' => $carer->phone_e164,
            ],
        ]);
    }
}
