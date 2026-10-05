<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Security;

/** Shared across FPM workers. Bounded slots, no database and no user-provided paths. */
final class RealtimeRateLimit
{
    public function allow(string $session, string $role, string $type): bool
    {
        $directory = sys_get_temp_dir().'/mautic-webchat-events';
        if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
            return false;
        }
        $file = @fopen($directory.'/'.(hexdec(substr(hash('sha256', $session.$role), 0, 4)) % 4096).'.json', 'c+');
        if (!$file || !flock($file, LOCK_EX)) {
            if ($file) fclose($file);
            return false;
        }
        try {
            $now = time();
            $state = json_decode(stream_get_contents($file) ?: '{}', true) ?: [];
            if (($state['second'] ?? 0) !== $now) {
                $state['second'] = $now;
                $state['burst'] = 0;
            }
            if (($state['minute'] ?? 0) !== intdiv($now, 60)) {
                $state['minute'] = intdiv($now, 60);
                $state['messages'] = $state['controls'] = 0;
            }
            $bucket = 'message.send' === $type ? 'messages' : 'controls';
            $allowed = ($state['burst'] ?? 0) < 5 && ($state[$bucket] ?? 0) < ('messages' === $bucket ? 30 : 60);
            if ($allowed) {
                ++$state['burst'];
                ++$state[$bucket];
            }
            rewind($file);
            ftruncate($file, 0);
            fwrite($file, json_encode($state, JSON_THROW_ON_ERROR));
            return $allowed;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
