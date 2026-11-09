@extends('mail.layout')

@section('content')
    <p>Bonjour {{ $userName }},</p>
    <p>Une réinitialisation du mot de passe de votre compte a été demandée.</p>
    <p><a href="{{ $url }}" style="display: inline-block; background: #0f766e; color: #ffffff; padding: 10px 16px; border-radius: 6px; text-decoration: none;">Choisir un nouveau mot de passe</a></p>
    <p>Ce lien est valable 60 minutes et ne peut être utilisé qu'une seule fois.</p>
    <p>Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.</p>
@endsection
