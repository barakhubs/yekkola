{{-- Certificate PDF (PRD-06 FR-10). A document template, not a page: the API serves no UI. --}}
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('certificate.title', [], $locale) }} {{ $certificate->serial }}</title>
    <style>
        @page { margin: 0; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; margin: 0; }
        .frame { position: absolute; top: 28px; right: 28px; bottom: 28px; left: 28px; border: 6px solid #2c3892; padding: 40px 56px; text-align: center; }
        .brand { color: #2c3892; font-size: 26px; font-weight: bold; letter-spacing: 2px; }
        .accent { width: 120px; height: 4px; background: #fdb73b; margin: 10px auto 24px; }
        h1 { font-size: 30px; margin: 0 0 28px; color: #2c3892; }
        .muted { color: #475569; font-size: 13px; }
        .name { font-size: 34px; font-weight: bold; margin: 8px 0 20px; }
        .course { font-size: 22px; font-weight: bold; margin: 8px 0 6px; }
        .footer { position: absolute; left: 56px; right: 56px; bottom: 40px; text-align: left; }
        .footer td { vertical-align: bottom; font-size: 11px; color: #475569; }
    </style>
</head>
<body>
    <div class="frame">
        <div class="brand">YEKKOLA</div>
        <div class="accent"></div>
        <h1>{{ __('certificate.title', [], $locale) }}</h1>
        <div class="muted">{{ __('certificate.awarded_to', [], $locale) }}</div>
        <div class="name">{{ $certificate->student_name_snapshot }}</div>
        <div class="muted">{{ __('certificate.for_completing', [], $locale) }}</div>
        <div class="course">{{ $certificate->course_title_snapshot }}</div>
        <div class="muted">{{ __('certificate.taught_by', ['professor' => $certificate->professor_name_snapshot], $locale) }}</div>

        <table class="footer" width="100%">
            <tr>
                <td>
                    {{ __('certificate.issued_on', ['date' => $certificate->issued_at->locale($locale)->isoFormat('LL')], $locale) }}<br>
                    {{ __('certificate.serial', ['serial' => $certificate->serial], $locale) }}<br>
                    {{ __('certificate.verify', ['url' => $certificate->verificationUrl()], $locale) }}
                </td>
                <td style="text-align: right;"><img src="{{ $qr }}" width="110" height="110" alt=""></td>
            </tr>
        </table>
    </div>
</body>
</html>
