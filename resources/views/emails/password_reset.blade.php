<p>Hello {{ $user->name }},</p>

<p>You requested a password reset for your {{ config('app.name') }} account.</p>

<p>
    Click the link below to set a new password (valid for 60 minutes):
</p>

<p>
    <a href="{{ $resetUrl }}">{{ $resetUrl }}</a>
</p>

<p>If you did not request this, you can safely ignore this email.</p>

<p>Regards,<br>{{ config('app.name') }} Team</p>