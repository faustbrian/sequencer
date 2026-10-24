<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\DeferredBound;

use Cline\Sequencer\Contracts\DeferredOperation;
use Illuminate\Support\Sleep;

use const FILE_APPEND;
use const SIG_IGN;
use const SIGTERM;

use function app;
use function fclose;
use function file_put_contents;
use function getmypid;
use function hrtime;
use function is_file;
use function is_resource;
use function json_encode;
use function pcntl_async_signals;
use function pcntl_signal;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;

final class ProbeOperation implements DeferredOperation
{
    public function handle(array $payload): void
    {
        $path = $payload['path'];

        $acceptedBeforeAck = ($payload['mode'] ?? '') === 'accept_then_block';
        $firstAcceptance = !is_file($path.'.accepted');

        if ($acceptedBeforeAck) {
            file_put_contents($path.'.accepted', "accepted\n", FILE_APPEND);
        }

        if (($payload['mode'] ?? '') === 'ignore' || ($acceptedBeforeAck && $firstAcceptance)) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, SIG_IGN);
            $server = stream_socket_server('tcp://127.0.0.1:0');
            file_put_contents($path.'.entered', json_encode(['pid' => getmypid(), 'started_ns' => hrtime(true), 'address' => stream_socket_get_name($server, false)]));
            $peer = stream_socket_accept($server, 180);

            if (is_resource($peer)) {
                fclose($peer);
            }

            fclose($server);
        } else {
            file_put_contents($path.'.entered', json_encode(['pid' => getmypid(), 'cached_config' => app()->configurationIsCached()]));
            Sleep::usleep((int) ($payload['microseconds'] ?? 0));
        }

        file_put_contents($path.'.effect', 'completed');
    }
}
