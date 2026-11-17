@php
    $bag = $bag ?? 'default';
    $saved = $saved ?? [];
@endphp

@foreach (range(0, \App\Services\PasswordRecovery::QUESTION_COUNT - 1) as $index)
    <div class="form-grid">
        <div class="field">
            <label for="security_{{ $index }}_question">{{ __('Question :v1', ['v1' => $index + 1]) }} <span class="req">*</span></label>
            <select id="security_{{ $index }}_question" name="security[{{ $index }}][question]" required>
                <option value="">{{ __('Choisir…') }}</option>
                @foreach (config('security_questions') as $key => $text)
                    <option value="{{ $key }}" @selected(old("security.$index.question", $saved[$index] ?? null) === $key)>{{ __($text) }}</option>
                @endforeach
            </select>
            @error("security.$index.question", $bag)<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="security_{{ $index }}_answer">{{ __('Réponse') }} <span class="req">*</span></label>
            <input id="security_{{ $index }}_answer" type="text" name="security[{{ $index }}][answer]" maxlength="100" required autocomplete="off">
            @error("security.$index.answer", $bag)<div class="error">{{ $message }}</div>@enderror
        </div>
    </div>
@endforeach
