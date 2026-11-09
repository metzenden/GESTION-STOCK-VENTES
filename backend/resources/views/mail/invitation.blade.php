@extends('mail.layout')

@section('content')
    <p>Bonjour,</p>
    <p>Vous êtes invité(e) à rejoindre <strong>{{ $companyName }}</strong> sur FDS Business avec le rôle <strong>{{ $roleName }}</strong>.</p>
    <p><a href="{{ $url }}" style="display: inline-block; background: #0f766e; color: #ffffff; padding: 10px 16px; border-radius: 6px; text-decoration: none;">Activer mon compte</a></p>
    <p>Ce lien est valable jusqu'au {{ $expiresAt }} et ne peut être utilisé qu'une seule fois.</p>
    <p>Si vous n'attendiez pas cette invitation, ignorez ce message.</p>
@endsection
