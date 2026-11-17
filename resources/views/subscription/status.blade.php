<p class="plan-status">
    <span class="badge {{ $organisation->subscriptionBadge() }}">{{ $organisation->subscriptionLabel() }}</span>
    @if (! $organisation->subscribed_until && $organisation->trial_ends_at)
        {{ __('Fin de l\'essai : :v1', ['v1' => $organisation->trial_ends_at->format('d/m/Y')]) }}
    @endif
    @if ($organisation->subscribed_until)
        {{ __('Échéance : :v1', ['v1' => $organisation->subscribed_until->format('d/m/Y')]) }}
    @endif
</p>
