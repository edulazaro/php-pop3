<?php

namespace EduLazaro\Pop3\Tests;

use EduLazaro\Pop3\Connection;
use EduLazaro\Pop3\Exceptions\ConnectionFailed;

/**
 * A server that answers from a script: each line the client sends is recorded, and each read
 * returns the next scripted line.
 */
final class ScriptedConnection implements Connection
{
    /** @var list<string> */
    public array $sent = [];

    public bool $crypto = false;

    public bool $closed = false;

    /**
     * @param  list<string>  $lines  What the server says, in order, without line endings.
     */
    public function __construct(private array $lines) {}

    /**
     * @param  string  $line
     * @return void
     */
    public function writeLine(string $line): void
    {
        $this->sent[] = $line;
    }

    /**
     * @return string
     */
    public function readLine(): string
    {
        if ($this->lines === []) {
            throw new ConnectionFailed('The connection was closed by the server.');
        }

        return array_shift($this->lines)."\r\n";
    }

    /**
     * @return void
     */
    public function enableCrypto(): void
    {
        $this->crypto = true;
    }

    /**
     * @return void
     */
    public function close(): void
    {
        $this->closed = true;
    }
}
