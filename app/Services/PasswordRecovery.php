<?php

namespace App\Services;

use App\Models\Organisation;
use App\Models\PasswordSupportRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Récupération du mot de passe sans e-mail : questions de sécurité ou support.
 * Les réponses sont hachées comme un mot de passe : personne ne peut les relire, pas même le support.
 */
class PasswordRecovery
{
    public const QUESTION_COUNT = 3;

    public function normalizeCode(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    public function normalizeLogin(?string $login): string
    {
        return Str::lower(trim((string) $login));
    }

    public function findUser(string $code, string $login): ?User
    {
        $organisation = Organisation::where('code', $code)->first();

        return $organisation ? $organisation->users()->where('email', $login)->first() : null;
    }

    // « Yaoundé  » et « yaounde » valent la même réponse.
    public function normalizeAnswer(string $answer): string
    {
        return preg_replace('/\s+/', ' ', trim(Str::lower(Str::ascii($answer))));
    }

    /**
     * Questions à poser. Un compte inconnu ou sans questions reçoit toujours les mêmes questions
     * fictives : la page ne révèle donc pas quels comptes existent.
     */
    public function questionsFor(?User $user, string $code, string $login): array
    {
        $saved = $user?->securityAnswers()->orderBy('id')->pluck('question')->all() ?? [];

        if (count($saved) >= self::QUESTION_COUNT) {
            return $saved;
        }

        $keys = array_keys(config('security_questions'));
        usort($keys, fn ($a, $b) => strcmp(sha1($code.'|'.$login.'|'.$a), sha1($code.'|'.$login.'|'.$b)));

        return array_slice($keys, 0, self::QUESTION_COUNT);
    }

    // Toutes les réponses doivent correspondre. Le calcul a lieu même pour un compte inconnu.
    public function answersMatch(?User $user, array $questions, array $answers): bool
    {
        $saved = $user?->securityAnswers()->pluck('answer', 'question') ?? collect();
        $complete = $saved->count() >= self::QUESTION_COUNT;
        $match = $complete;

        foreach ($questions as $question) {
            $answer = $this->normalizeAnswer((string) ($answers[$question] ?? ''));
            // Empreinte fixe quand la question n'existe pas : le temps de réponse reste le même.
            $hash = $saved[$question] ?? Hash::make('compte-inconnu');
            $match = Hash::check($answer, $hash) && $match;
        }

        return $match;
    }

    public function saveAnswers(User $user, array $entries): void
    {
        DB::transaction(function () use ($user, $entries) {
            $user->securityAnswers()->delete();

            foreach ($entries as $entry) {
                $user->securityAnswers()->create([
                    'question' => $entry['question'],
                    'answer' => Hash::make($this->normalizeAnswer($entry['answer'])),
                ]);
            }
        });
    }

    // Change le mot de passe et ferme les sessions ouvertes, y compris celle qui bloquerait la reconnexion.
    public function setPassword(User $user, string $password, bool $mustChange): void
    {
        $user->forceFill([
            'password' => $password,
            'must_change_password' => $mustChange,
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    public function closeRequestsSolvedByCustomer(User $user): void
    {
        PasswordSupportRequest::where('user_id', $user->id)->where('status', PasswordSupportRequest::PENDING)
            ->update(['status' => PasswordSupportRequest::SELF_SERVICE, 'handled_at' => now()]);
    }

    public function rules(): array
    {
        return [
            'security' => ['required', 'array', 'size:'.self::QUESTION_COUNT],
            'security.*.question' => ['required', 'distinct', Rule::in(array_keys(config('security_questions')))],
            'security.*.answer' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['security.*.question.distinct' => __('Choisissez trois questions différentes.')];
    }

    public function attributes(): array
    {
        return ['security.*.question' => __('question'), 'security.*.answer' => __('réponse')];
    }
}
