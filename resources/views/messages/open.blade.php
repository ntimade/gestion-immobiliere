@extends('layouts.guest')
@section('title', __('Message prêt'))
@section('heading', __('Message prêt'))

@section('content')
    <div class="message-open">
        <p class="soft">{{ $channel === 'sms' ? __('Touchez le bouton pour ouvrir les SMS avec le message déjà écrit.') : __('Touchez le bouton pour ouvrir WhatsApp avec le message déjà écrit.') }}</p>

        <a class="btn block" href="{{ $launch }}" data-message-launch>
            <x-icon :name="$channel === 'sms' ? 'message-square' : 'send'" />
            {{ $channel === 'sms' ? __('Ouvrir les SMS') : __('Ouvrir WhatsApp') }}
        </a>

        <a class="btn ghost block" href="{{ $back }}">{{ __('Retour à la liste') }}</a>

        @if ($channel === 'whatsapp')
            <p class="soft small">{{ __('Si rien ne s\'ouvre, vérifiez que WhatsApp est installé sur ce téléphone.') }}</p>
        @endif
    </div>
@endsection
