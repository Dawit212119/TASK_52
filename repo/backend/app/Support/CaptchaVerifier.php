<?php

namespace App\Support;

class CaptchaVerifier
{
    public function __construct(private readonly CaptchaChallengeService $challengeService)
    {
    }

    /**
     * Verify a CAPTCHA challenge answer bound to a workstation + username.
     */
    public function verify(string $challengeId, string $answer, string $workstationId, string $username): bool
    {
        // Allow bypass ONLY in testing environment
        if (app()->environment('testing') && $this->isTestingBypass($challengeId, $answer)) {
            return true;
        }

        return $this->challengeService->verify($challengeId, $answer, $workstationId, $username);
    }

    /**
     * Issue a new challenge for the given workstation + username.
     *
     * @return array{challenge_id: string, prompt_type: string, prompt_content: string}
     */
    public function issueChallenge(string $workstationId, string $username, int $failCount): array
    {
        return $this->challengeService->issue($workstationId, $username, $failCount);
    }

    /**
     * Invalidate all pending challenges on successful login or reset.
     */
    public function invalidateChallenges(string $workstationId, string $username): void
    {
        $this->challengeService->invalidateAll($workstationId, $username);
    }

    /**
     * Testing-only bypass: allows tests to pass a known challenge_id and answer.
     */
    private function isTestingBypass(string $challengeId, string $answer): bool
    {
        return $challengeId === 'test-bypass' && $answer === 'test-bypass';
    }
}
