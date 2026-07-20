<?php

namespace Rouxtaccess\Sync\Concerns;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

trait StreamsProcessProgress
{
    /**
     * Run a long command with an untimed process, splitting its live output into
     * lines and handing each to $onLine as ($stream, $line), where $stream is
     * 'out' or 'err'. This replaces a blocking spin() so a caller can drive a
     * progress bar from what the command prints. rsync writes its progress with
     * carriage returns, so both \r and \n terminate a line.
     *
     * @param  array<int, string>|string  $command
     * @param  Closure(string, string): void  $onLine
     */
    protected function streamProcess(array|string $command, Closure $onLine): ProcessResult
    {
        $buffers = ['out' => '', 'err' => ''];

        $result = Process::timeout(0)->run($command, function (string $type, string $chunk) use (&$buffers, $onLine): void {
            $stream = $type === SymfonyProcess::ERR ? 'err' : 'out';
            $buffers[$stream] .= $chunk;

            while (preg_match('/^(.*?)[\r\n]/', $buffers[$stream], $matches) === 1) {
                $buffers[$stream] = substr($buffers[$stream], strlen($matches[0]));
                $line = trim($matches[1]);

                if ($line !== '') {
                    $onLine($stream, $line);
                }
            }
        });

        // Deliver any final line the command left without a trailing newline, so
        // the last progress marker is not dropped.
        foreach ($buffers as $stream => $remainder) {
            $line = trim($remainder);

            if ($line !== '') {
                $onLine($stream, $line);
            }
        }

        return $result;
    }
}
