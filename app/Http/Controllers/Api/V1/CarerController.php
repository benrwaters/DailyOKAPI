<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Carer;
use App\Models\CarerPatient;
use App\Models\CheckInSchedule;
use App\Models\CheckIn;
use App\Models\Patient;
use App\Models\PatientInvite;
use App\Services\BulkSmsClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
 
class CarerController extends Controller
{
    public function profile(Request $request)
    {
        /** @var Carer $carer */
        $carer = $request->user();

        return response()->json([
            'ok' => true,
            'profile' => [
                'id' => $carer->id,
                'full_name' => $carer->full_name,
                'email' => $carer->email,
                'mobile_number' => $carer->phone_e164,
                'phone_e164' => $carer->phone_e164,
                'status' => $carer->status,
            ],
        ]);
    }

    public function update_profile(Request $request)
    {
        /** @var Carer $carer */
        $carer = $request->user();

        $validated = $request->validate([
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'mobile_number' => ['sometimes', 'nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'phone_e164' => ['sometimes', 'nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);

        if (array_key_exists('full_name', $validated)) {
            $carer->full_name = $validated['full_name'];
        }

        if (array_key_exists('email', $validated)) {
            $carer->email = $validated['email'];
        }

        if (array_key_exists('mobile_number', $validated)) {
            $carer->phone_e164 = $validated['mobile_number'];
        } elseif (array_key_exists('phone_e164', $validated)) {
            $carer->phone_e164 = $validated['phone_e164'];
        }

        $carer->save();

        return response()->json([
            'ok' => true,
            'profile' => [
                'id' => $carer->id,
                'full_name' => $carer->full_name,
                'email' => $carer->email,
                'mobile_number' => $carer->phone_e164,
                'phone_e164' => $carer->phone_e164,
                'status' => $carer->status,
            ],
        ]);
    }

    public function resend_loved_one_invite(Request $request, string $invite_id, BulkSmsClient $bulksms)
    {
        /** @var Carer $carer */
        $carer = $request->user();

        $request->validate([
            'reason' => ['nullable', 'string', 'max:64'],
        ]);

        $invite = $this->find_invite_for_carer_or_404($carer->id, $invite_id);

        // only resend if still pending
        if (!$invite->is_active || $invite->first_used_at !== null) {
            return response()->json([
                'ok' => false,
                'error' => 'not_resendable',
                'message' => 'Invite can’t be resent.',
            ], 409);
        }

        $invite->loadMissing('patient');

       // $app_link_placeholder = '[app link coming soon]';
        $carer_name = $carer->full_name ?: 'Someone';

        $sms_body =
            "Hi! {$carer_name} has invited you to do a simple daily check-in.\n\n"
            . "Your code: {$invite->code}\n\n" ;

        $send = $bulksms->send_sms($invite->patient->phone_e164, $sms_body);

        $invite->last_sent_at = now();
        $invite->save();

        $this->debug_invite_email(
            subject: 'DailyOK invite resend (debug)',
            carer: $carer,
            loved_one_phone: $invite->patient->phone_e164,
            invite_code: $invite->code,
            sms_body: $sms_body,
            extra: [
                'invite_id' => $invite->id,
                'reason' => $request->input('reason'),
                'sms_ok' => $send['ok'] ? 'yes' : 'no',
            ]
        );

        if (!$send['ok']) {
            Log::warning('invite resend sms failed', [
                'invite_id' => $invite->id,
                'send' => $send,
            ]);
        }

        return response()->json([
            'ok' => true,
            'invite' => [
                'id' => 'inv_' . $invite->id,
                'status' => $this->invite_ui_status($invite),
                'last_sent_at' => $invite->last_sent_at ? $invite->last_sent_at->toIso8601String() : null,
            ],
        ]);
    }

    public function cancel_loved_one_invite(Request $request, string $invite_id)
    {
        /** @var Carer $carer */
        $carer = $request->user();

        $request->validate([
            'reason' => ['nullable', 'string', 'max:64'],
        ]);

        $invite = $this->find_invite_for_carer_or_404($carer->id, $invite_id);

        // if already used, don’t allow cancel (optional – you can relax later)
        if ($invite->first_used_at !== null) {
            return response()->json([
                'ok' => false,
                'error' => 'already_accepted',
                'message' => 'Invite already accepted.',
            ], 409);
        }

        $invite->is_active = false;
        $invite->save();

        $this->debug_invite_email(
            subject: 'DailyOK invite cancelled (debug)',
            carer: $carer,
            loved_one_phone: null,
            invite_code: $invite->code,
            sms_body: null,
            extra: [
                'invite_id' => $invite->id,
                'reason' => $request->input('reason'),
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function delete_loved_one(Request $request, string $id)
    {
        /** @var Carer $carer */
        $carer = $request->user();

        $patientId = $this->normalise_patient_id($id);

        $link = CarerPatient::query()
            ->where('carer_id', $carer->id)
            ->where('patient_id', $patientId)
            ->first();

        if (!$link) {
            return response()->json([
                'ok' => false,
                'error' => 'not_found',
                'message' => 'Contact not found.',
            ], 404);
        }

        $otherCarerCount = CarerPatient::query()
            ->where('patient_id', $patientId)
            ->where('carer_id', '!=', $carer->id)
            ->count();

        if ($otherCarerCount > 0) {
            return response()->json([
                'ok' => false,
                'error' => 'contact_shared',
                'message' => 'This contact is linked to another carer and cannot be fully removed automatically.',
            ], 409);
        }

        DB::transaction(function () use ($patientId) {
            $patient = Patient::query()->findOrFail($patientId);

            $patient->tokens()->delete();
            $patient->devices()->delete();
            $patient->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function loved_one_check_ins(Request $request, string $id)
    {
        /** @var Carer $carer */
        $carer = $request->user();

        $patientId = $this->normalise_patient_id($id);

        $link = CarerPatient::query()
            ->where('carer_id', $carer->id)
            ->where('patient_id', $patientId)
            ->first();

        if (!$link) {
            return response()->json([
                'ok' => false,
                'error' => 'not_found',
                'message' => 'Contact not found.',
            ], 404);
        }

        $patient = Patient::query()->findOrFail($patientId);
        $checkIns = CheckIn::query()
            ->where('patient_id', $patientId)
            ->orderByDesc('checked_in_at')
            ->get();

        return response()->json([
            'ok' => true,
            'contact' => [
                'id' => (string) $patient->id,
                'display_name' => $patient->display_name,
                'phone_e164' => $patient->phone_e164,
                'status' => $patient->status,
            ],
            'check_ins' => $checkIns->map(fn ($checkIn) => [
                'id' => (string) $checkIn->id,
                'checked_in_at' => optional($checkIn->checked_in_at)->toIso8601String(),
                'type' => $checkIn->type,
                'created_at' => optional($checkIn->created_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    private function find_invite_for_carer_or_404(int $carer_id, string $invite_id): PatientInvite
    {
        $numeric_id = $this->normalise_invite_id($invite_id);

        return PatientInvite::where('id', $numeric_id)
            ->where('carer_id', $carer_id)
            ->firstOrFail();
    }

    private function normalise_invite_id(string $invite_id): int
    {
        // Accept "inv_123" or "123"
        if (str_starts_with($invite_id, 'inv_')) {
            $invite_id = substr($invite_id, 4);
        }

        if (!ctype_digit($invite_id)) {
            abort(response()->json([
                'ok' => false,
                'error' => 'invalid_invite_id',
            ], 422));
        }

        return (int) $invite_id;
    }

    private function normalise_patient_id(string $patient_id): int
    {
        if (str_starts_with($patient_id, 'lov_')) {
            $patient_id = substr($patient_id, 4);
        }

        if (!ctype_digit($patient_id)) {
            abort(response()->json([
                'ok' => false,
                'error' => 'invalid_contact_id',
            ], 422));
        }

        return (int) $patient_id;
    }

    private function debug_invite_email(
        string $subject,
        Carer $carer,
        ?string $loved_one_phone,
        ?string $invite_code,
        ?string $sms_body,
        array $extra = []
    ): void {
        try {
            $debug_email = "ben@spinningtheweb.co.uk"; // keep as-is for now

            if (empty($debug_email)) {
                return;
            }

            $lines = [];
            $lines[] = $subject;
            $lines[] = '';
            $lines[] = 'Carer ID: ' . $carer->id;
            $lines[] = 'Carer name: ' . ($carer->full_name ?: 'Someone');

            if (!empty($loved_one_phone)) {
                $lines[] = 'Loved one phone: ' . $loved_one_phone;
            }

            if (!empty($invite_code)) {
                $lines[] = 'Invite code: ' . $invite_code;
            }

            foreach ($extra as $k => $v) {
                $lines[] = "{$k}: " . (is_scalar($v) ? (string) $v : json_encode($v));
            }

            if (!empty($sms_body)) {
                $lines[] = '';
                $lines[] = '----- SMS BODY START -----';
                $lines[] = $sms_body;
                $lines[] = '----- SMS BODY END -----';
            }

            Mail::raw(implode("\n", $lines), function ($m) use ($debug_email, $subject) {
                $m->to($debug_email)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('debug invite email failed', ['error' => $e->getMessage()]);
        }
    }


    public function create_loved_one_and_invite(Request $request, BulkSmsClient $bulksms)
    {
        /** @var Carer $carer */
        $carer = $request->user();

        $validated = $request->validate([
            'loved_one_name' => ['nullable', 'string', 'max:255'],
            'loved_one_phone_e164' => ['required', 'string', 'max:32'],

            'verification_hint' => ['required', 'string', 'in:last_name,dob_day_month'],
            'verification_value' => ['required', 'string', 'max:255'],

            'cadence' => ['required', 'string', 'in:daily'],
            'timezone' => ['required', 'string', 'max:64'],
            'check_in_time_local' => ['required', 'string', 'max:5'], // "HH:mm"
            'reminder_minutes_before' => ['required', 'integer', 'min:0', 'max:240'],
            'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
        ]);

        $patient = new Patient();
        $patient->display_name = $validated['loved_one_name'] ?? null;
        $patient->phone_e164 = $validated['loved_one_phone_e164'];
        $patient->verification_hint = $validated['verification_hint'];

        // never store raw; use password hashing (bcrypt)
        $patient->verification_hash = password_hash($validated['verification_value'], PASSWORD_BCRYPT);
        $patient->status = 'active';
        $patient->save();

        $link = new CarerPatient();
        $link->carer_id = $carer->id;
        $link->patient_id = $patient->id;
        $link->is_primary = true;
        $link->save();

        $schedule = new CheckInSchedule();
        $schedule->patient_id = $patient->id;
        $schedule->cadence = $validated['cadence'];
        $schedule->timezone = $validated['timezone'];
        $schedule->check_in_time_local = $validated['check_in_time_local'];
        $schedule->reminder_minutes_before = (int) $validated['reminder_minutes_before'];
        $schedule->grace_minutes = (int) ($validated['grace_minutes'] ?? 120);
        $schedule->status = 'active';
        $schedule->save();

        $code = $this->generate_invite_code();

        $invite = new PatientInvite();
        $invite->carer_id = $carer->id;
        $invite->patient_id = $patient->id;
        $invite->code = $code;
        $invite->is_active = true;
        $invite->issued_at = now();
        $invite->save();

        ///$app_link_placeholder = '[app link coming soon]';

        $carer_name = $carer->full_name ?: 'Someone';

        $sms_body =
            "Hi! {$carer_name} has invited you to do a simple daily check-in.\n\n"
            . "Your code: {$code}\n\n";
//          . "Get the app here: {$app_link_placeholder}"

        $send = $bulksms->send_sms($patient->phone_e164, $sms_body);

        // debug email (never hard-fail the endpoint)
        try {
            $debug_email = "ben@spinningtheweb.co.uk";
            if (!empty($debug_email)) {
                Mail::raw(
                    "DailyOK Invite (debug)\n\nCarer ID: {$carer->id}\nLoved one phone: {$patient->phone_e164}\nInvite code: {$code}\nSchedule: {$schedule->cadence} @ {$schedule->check_in_time_local} ({$schedule->timezone})\nReminder: {$schedule->reminder_minutes_before} mins\nVerification hint: {$patient->verification_hint}",
                    function ($m) use ($debug_email) {
                        $m->to($debug_email)->subject('DailyOK invite (debug)');
                    }
                );
            }
        } catch (\Throwable $e) {
            Log::warning('invite debug email failed', ['error' => $e->getMessage()]);
        }

        if (!$send['ok']) {
            Log::warning('invite sms failed', [
                'patient_id' => $patient->id,
                'send' => $send,
            ]);
        }

        return response()->json([
            'ok' => true,
            'loved_one' => [
                'id' => $patient->id,
                'name' => $patient->display_name,
                'phone_e164' => $patient->phone_e164,
            ],
            'invite' => [
                'code' => $code, // helpful for dev
                'sent' => (bool) $send['ok'],
                'provider' => 'bulksms',
            ],
            'schedule' => [
                'cadence' => $schedule->cadence,
                'timezone' => $schedule->timezone,
                'check_in_time_local' => $schedule->check_in_time_local,
                'reminder_minutes_before' => $schedule->reminder_minutes_before,
            ],
        ]);
    }

    private function generate_invite_code(): string
    {
        // 4 chars, readable, no 0/O/1/I
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';

        for ($i = 0; $i < 4; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // If collision happens, retry once (extremely unlikely for MVP)
        if (\App\Models\PatientInvite::where('code', $code)->exists()) {
            return $this->generate_invite_code();
        }

        return $code;
    }

     public function list_loved_one_invites(Request $request)
    {
        /** @var Carer $carer */
        $carer = $request->user();

        $status = strtolower((string) $request->query('status', 'pending'));

        if ($status === 'active') {
            return $this->list_active_loved_ones($carer);
        }

        // default: pending invites
        return $this->list_pending_invites($request, $carer);
    }

    private function list_pending_invites(Request $request, Carer $carer)
    {
        $invites = PatientInvite::query()->with('patient')
            ->where('carer_id', $carer->id)
            ->when($request->query('status') === 'pending', function ($q) {
                // Pending = active + unused + not expired
                $q->where('is_active', true)
                  ->whereNull('first_used_at')
                  ->where(function ($qq) {
                      $qq->whereNull('expires_at')->orWhere('expires_at', '>', now());
                  });
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'ok' => true,
            'invites' => $invites->map(function ($inv) {
                return [
                    'id' => (string) $inv->id,
                    'name' => optional($inv->patient)->display_name ?? 'Loved one',
                    'phone_e164' => optional($inv->patient)->phone_e164 ?? '',
                    'status' => 'pending',
                    'created_at' => optional($inv->created_at)->toIso8601String(),
                    'invite_code' => $inv->code, // keep for dev, remove later if desired
                ];
            })->values(),
        ]);
    }

    private function list_active_loved_ones(Carer $carer)
    {
        // Active loved ones are the carer_patients links, joined to patient + schedule.
        $rows = CarerPatient::query()
            ->where('carer_id', $carer->id)
            ->join('patients', 'patients.id', '=', 'carer_patients.patient_id')
            ->leftJoin('check_in_schedules', 'check_in_schedules.patient_id', '=', 'patients.id')
            ->select([
                'patients.id as patient_id',
                'patients.display_name',
                'patients.phone_e164',
                'check_in_schedules.timezone',
                'check_in_schedules.last_check_in_at',
                'check_in_schedules.next_due_at',
            ])
            ->orderBy('patients.display_name')
            ->get();

        $patientIds = $rows->pluck('patient_id')->all();
        $recentCheckIns = CheckIn::query()
            ->whereIn('patient_id', $patientIds)
            ->orderByDesc('checked_in_at')
            ->get()
            ->groupBy('patient_id')
            ->map(fn ($items) => $items->take(10)->values());

        return response()->json([
            'ok' => true,
            'loved_ones' => $rows->map(function ($r) use ($recentCheckIns) {
                return [
                    'id' => (string) $r->patient_id,
                    'display_name' => $r->display_name ?: 'Loved one',
                    'phone_e164' => $r->phone_e164 ?: '',
                    'status' => 'active',
                    'timezone' => $r->timezone,
                    'last_check_in_at' => $r->last_check_in_at ? \Carbon\Carbon::parse($r->last_check_in_at)->toIso8601String() : null,
                    'next_due_at' => $r->next_due_at ? \Carbon\Carbon::parse($r->next_due_at)->toIso8601String() : null,
                    'recent_check_ins' => ($recentCheckIns[$r->patient_id] ?? collect())->map(fn ($checkIn) => [
                        'id' => (string) $checkIn->id,
                        'checked_in_at' => optional($checkIn->checked_in_at)->toIso8601String(),
                        'type' => $checkIn->type,
                    ])->values(),
                ];
            })->values(),
        ]);
    }

    private function invite_ui_status(PatientInvite $invite): string
    {
        if (!$invite->is_active) {
            return 'cancelled';
        }

        if (!is_null($invite->first_used_at)) {
            return 'accepted';
        }

        if (!is_null($invite->expires_at) && $invite->expires_at->isPast()) {
            return 'expired';
        }

        return 'pending';
    }

}
