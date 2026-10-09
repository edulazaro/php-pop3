<?php

namespace EduLazaro\Pop3\Tests;

use EduLazaro\Pop3\Client;
use EduLazaro\Pop3\Encryption;
use EduLazaro\Pop3\Exceptions\ConnectionFailed;
use PHPUnit\Framework\TestCase;

/**
 * Against a real server: GreenMail (`greenmail/standalone`), as the CI runs it. Set
 * POP3_TEST_HOST (and optionally POP3_TEST_SMTP_PORT, POP3_TEST_POP_PORT, POP3_TEST_POPS_PORT)
 * to run these; skipped otherwise.
 */
class GreenMailTest extends TestCase
{
    private string $host;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->host = (string) getenv('POP3_TEST_HOST');

        if ($this->host === '') {
            $this->markTestSkipped('Set POP3_TEST_HOST to run against GreenMail.');
        }
    }

    /**
     * A user of its own per test, so tests do not see each other's mail.
     *
     * @param  string  $subject
     * @return string
     */
    private function deliver(string $subject): string
    {
        $user = uniqid('box').'@example.test';
        $smtp = fsockopen($this->host, (int) (getenv('POP3_TEST_SMTP_PORT') ?: 3025));
        $say = function (string $line) use ($smtp) {
            fwrite($smtp, $line."\r\n");

            return fgets($smtp);
        };

        fgets($smtp);
        $say('HELO test');
        $say('MAIL FROM:<ana@acme.test>');
        $say("RCPT TO:<{$user}>");
        $say('DATA');
        $say("From: Ana <ana@acme.test>\r\nTo: {$user}\r\nSubject: {$subject}\r\nMessage-ID: <".uniqid()."@acme.test>\r\n\r\nFirst line\r\n.starts with a dot\r\n.");
        $say('QUIT');
        fclose($smtp);

        return $user;
    }

    public function test_it_reads_a_real_mailbox_and_leaves_it_as_it_was(): void
    {
        $user = $this->deliver('Cena de empresa');

        foreach ([1, 2] as $session) {
            $pop = Client::connect($this->host, (int) (getenv('POP3_TEST_POP_PORT') ?: 3110), Encryption::None);
            $pop->login($user, 'secret');

            $uids = $pop->uids();
            $this->assertCount(1, $uids, "Session {$session}");

            $raw = $pop->retrieve(array_key_first($uids));
            $this->assertStringContainsString('Subject: Cena de empresa', $raw);
            $this->assertStringContainsString("\r\n.starts with a dot\r\n", $raw);
            $this->assertStringContainsString('Subject: Cena de empresa', $pop->top(array_key_first($uids)));

            $pop->quit();
        }
    }

    public function test_tls_checks_the_certificate_unless_told_not_to(): void
    {
        $user = $this->deliver('Por TLS');
        $port = (int) (getenv('POP3_TEST_POPS_PORT') ?: 3995);

        $pop = Client::connect($this->host, $port, Encryption::Ssl, verifyCertificate: false);
        $pop->login($user, 'secret');
        $this->assertCount(1, $pop->uids());
        $pop->quit();

        $this->expectException(ConnectionFailed::class);
        Client::connect($this->host, $port, Encryption::Ssl);
    }
}
