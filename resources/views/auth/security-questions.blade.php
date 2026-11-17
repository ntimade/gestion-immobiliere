@extends('layouts.guest')
@section('title', __('Questions de sécurité'))
@section('heading', __('Questions de sécurité'))
@section('subheading', __('Répondez aux questions choisies à la création de votre compte.'))

@section('content')
    <form method="POST" action="{{ route('password.verify') }}">
        @csrf
        @foreach ($questions as $question)
            <div class="field">
                <label for="answer_{{ $question }}">{{ __(config("security_questions.$question")) }} <span class="req">*</span></label>
                <input id="answer_{{ $question }}" type="text" name="answers[{{ $question }}]" maxlength="100" required autocomplete="off" @if ($loop->first) autofocus @endif>
            </div>
        @endforeach
        <button type="submit" class="btn block">{{ __('Vérifier') }}</button>
    </form>
    <p class="auth-foot">{{ __('Réponses oubliées ?') }} <a href="{{ route('support.request') }}">{{ __('Contacter le support') }}</a></p>
    <p class="auth-foot"><a href="{{ route('login') }}">{{ __('Retour à la connexion') }}</a></p>
@endsection
