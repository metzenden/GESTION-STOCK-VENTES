@extends('mail.layout')

@section('content')
    <p>Bonjour {{ $userName }},</p>
    <p>Une connexion à votre compte super-administrateur a eu lieu le {{ $occurredAt }} (UTC).</p>
    <ul>
        <li>Adresse IP : {{ $ipAddress }}</li>
        <li>Navigateur : {{ $userAgent }}</li>
    </ul>
    <p>Si vous n'êtes pas à l'origine de cette connexion, changez immédiatement votre mot de passe et prévenez l'exploitant.</p>
@endsection
