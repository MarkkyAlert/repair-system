<?php
declare(strict_types=1);

namespace App\Services;

class LoginRateLimiter
{
    // Any key untouched for longer than this is beyond every decay window in use (login/reset 900s, guest
    // 600s), so it can be dropped on the next write — otherwise a key per unique (login|IP) accumulates
    // forever (only clear() ever removed one) and the JSON file grows unbounded. (logic-review R6-F2)
    private const GLOBAL_MAX_AGE_SECONDS = 3600;

    private string $filePath;

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath ?? storage_path('logs/login_rate_limits.json');
    }

    public function tooManyAttempts(string $key, int $maxAttempts = 5, int $decaySeconds = 900): bool
    {
        $attempts = $this->prune($this->read()[$key] ?? [], $decaySeconds);

        return count($attempts) >= $maxAttempts;
    }

    public function hit(string $key, int $decaySeconds = 900): void
    {
        $this->mutate(function (array $payload) use ($key, $decaySeconds): array {
            $attempts = $this->prune($payload[$key] ?? [], $decaySeconds);
            $attempts[] = time();
            $payload[$key] = $attempts;
            return $payload;
        });
    }

    public function clear(string $key): void
    {
        $this->mutate(function (array $payload) use ($key): array {
            unset($payload[$key]);
            return $payload;
        });
    }

    public function availableIn(string $key, int $decaySeconds = 900): int
    {
        $attempts = $this->prune($this->read()[$key] ?? [], $decaySeconds);
        if ($attempts === []) {
            return 0;
        }

        $oldestAttempt = min($attempts);
        $availableIn = ($oldestAttempt + $decaySeconds) - time();

        return $availableIn > 0 ? $availableIn : 0;
    }

    private function read(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }

        $handle = fopen($this->filePath, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return [];
            }
            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $this->decode((string) $contents);
    }

    private function mutate(callable $callback): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        // RISK MAP: If storage is not writable, login throttling silently degrades; deployment must verify permissions.
        $handle = fopen($this->filePath, 'c+');
        if ($handle === false) {
            error_log('[ratelimit] cannot open ' . $this->filePath . ' — login throttle DISABLED');
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                error_log('[ratelimit] flock failed on ' . $this->filePath . ' — login throttle DISABLED');
                return;
            }

            rewind($handle);
            $payload = $this->decode((string) stream_get_contents($handle));
            $payload = $callback($payload);
            $payload = $this->dropStaleKeys($payload);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function decode(string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Drop keys whose newest attempt is older than every decay window — bounds the file to active keys. */
    private function dropStaleKeys(array $payload): array
    {
        foreach ($payload as $key => $attempts) {
            $kept = is_array($attempts) ? $this->prune($attempts, self::GLOBAL_MAX_AGE_SECONDS) : [];
            if ($kept === []) {
                unset($payload[$key]);
            } else {
                $payload[$key] = $kept;
            }
        }

        return $payload;
    }

    private function prune(array $attempts, int $decaySeconds): array
    {
        $cutoff = time() - $decaySeconds;

        return array_values(array_filter($attempts, static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp >= $cutoff));
    }
}
