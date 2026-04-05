<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CaptchaChallengeService
{
    /**
     * Generate a new CAPTCHA challenge bound to the given workstation + username.
     *
     * @return array{challenge_id: string, prompt_type: string, prompt_content: string}
     */
    public function issue(string $workstationId, string $username, int $failCount): array
    {
        $challengeId = Str::uuid()->toString();
        [$prompt, $answer] = $this->generateMathChallenge();

        DB::table('login_captcha_challenges')->insert([
            'challenge_id' => $challengeId,
            'username' => mb_strtolower($username),
            'workstation_id' => mb_strtolower($workstationId),
            'answer_hash' => hash('sha256', (string) $answer),
            'prompt_type' => 'math',
            'prompt_content' => $prompt,
            'issued_at' => now()->utc(),
            'expires_at' => now()->utc()->addMinutes(5),
            'fail_count_context' => $failCount,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        return [
            'challenge_id' => $challengeId,
            'prompt_type' => 'math',
            'prompt_content' => $prompt,
        ];
    }

    /**
     * Verify a CAPTCHA challenge answer.
     *
     * Returns true if the challenge is valid, not expired, not used, bound to the
     * correct workstation + username, and the answer matches.
     */
    public function verify(string $challengeId, string $answer, string $workstationId, string $username): bool
    {
        $challenge = DB::table('login_captcha_challenges')
            ->where('challenge_id', $challengeId)
            ->first();

        if ($challenge === null) {
            return false;
        }

        if ($challenge->used_at !== null) {
            return false;
        }

        if (now()->utc()->greaterThan($challenge->expires_at)) {
            return false;
        }

        if (mb_strtolower($workstationId) !== mb_strtolower((string) $challenge->workstation_id)) {
            return false;
        }

        if (mb_strtolower($username) !== mb_strtolower((string) $challenge->username)) {
            return false;
        }

        $answerHash = hash('sha256', trim((string) $answer));
        if (! hash_equals((string) $challenge->answer_hash, $answerHash)) {
            return false;
        }

        // Mark as used
        DB::table('login_captcha_challenges')
            ->where('id', $challenge->id)
            ->update(['used_at' => now()->utc(), 'updated_at' => now()->utc()]);

        return true;
    }

    /**
     * Invalidate all pending challenges for a workstation + username.
     */
    public function invalidateAll(string $workstationId, string $username): void
    {
        DB::table('login_captcha_challenges')
            ->where('workstation_id', mb_strtolower($workstationId))
            ->where('username', mb_strtolower($username))
            ->whereNull('used_at')
            ->update(['used_at' => now()->utc(), 'updated_at' => now()->utc()]);
    }

    /**
     * @return array{0: string, 1: int} [prompt, answer]
     */
    private function generateMathChallenge(): array
    {
        $a = random_int(10, 99);
        $b = random_int(1, 49);
        $operators = ['+', '-'];
        $op = $operators[array_rand($operators)];

        $answer = $op === '+' ? $a + $b : $a - $b;
        $prompt = sprintf('What is %d %s %d?', $a, $op, $b);

        return [$prompt, $answer];
    }
}
