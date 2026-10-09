![PHP POP3](art/banner.png)

# php-pop3

<p>
    <a href="https://github.com/edulazaro/php-pop3/actions/workflows/tests.yml"><img src="https://github.com/edulazaro/php-pop3/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://packagist.org/packages/edulazaro/php-pop3"><img src="https://img.shields.io/packagist/v/edulazaro/php-pop3" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/edulazaro/php-pop3"><img src="https://img.shields.io/packagist/php-v/edulazaro/php-pop3" alt="PHP Version"></a>
    <a href="LICENSE"><img src="https://img.shields.io/packagist/l/edulazaro/php-pop3" alt="License"></a>
</p>

A read-only POP3 client in plain PHP. It connects over SSL or STARTTLS with a certificate check you control, lists the messages with their unique ids, reads a message's headers or the whole of it, and leaves the mailbox exactly as it was: there is nothing here that deletes. No PHP extension beyond OpenSSL, no dependencies, any framework or none.

PHP's `imap` extension, which most mail libraries use for POP3, left PHP's core in 8.4, and the pure-PHP POP3 clients left are unmaintained. This package does the one job they did: talk to the server. Parsing the messages it hands back is left to a mail parser, such as [webklex/php-imap](https://github.com/Webklex/php-imap).

## Install

It needs PHP 8.2 or later:

```bash
composer require edulazaro/php-pop3
```

## Read a mailbox

Connect, log in, list, read and quit:

```php
use EduLazaro\Pop3\Client;

$pop = Client::connect('pop.example.com');
$pop->login('info@example.com', 'secret');

foreach ($pop->uids() as $number => $uid) {
    $raw = $pop->retrieve($number);
}

$pop->quit();
```

`connect()` defaults to TLS on port 995 with the certificate checked. `uids()` returns each message's unique id by its number: numbers belong to one session, while ids stay the same across sessions, which is what tells a new message from one already read. `retrieve()` returns the message raw, exactly as the server sent it, and the message stays on the server.

## Only what is new

Remember the ids you have read, and skip them next time:

```php
$seen = $store->seenIds();

foreach ($pop->uids() as $number => $uid) {
    if (in_array($uid, $seen, true)) {
        continue;
    }

    $store->save($uid, $pop->retrieve($number));
}
```

To decide before downloading a whole message, `top()` returns its headers only, or its headers and the first lines of its body:

```php
$headers = $pop->top($number);       // headers only
$preview = $pop->top($number, 10);   // headers and the first 10 lines
```

`status()` gives the count and total size, and `sizes()` each message's size, to skip one that is too big.

## Encryption and certificates

Choose how the connection is protected:

```php
use EduLazaro\Pop3\Encryption;

Client::connect('pop.example.com');                                       // TLS from the start, port 995
Client::connect('pop.example.com', encryption: Encryption::StartTls);     // port 110, upgraded with STLS before the password
Client::connect('pop.example.com', 995, verifyCertificate: false);        // a self-signed certificate
Client::connect('localhost', 3110, Encryption::None);                     // clear text, for a test server only
```

The certificate is checked by default. Turn the check off only for a server whose certificate is self-signed or issued to another name: without it, anybody between you and the server can read the password. With `StartTls` the upgrade happens before anything else is sent, so the password never travels in clear.

Every answer has a timeout (`timeout: 30` seconds by default), so a server that stops answering fails instead of hanging a worker.

## Parse the messages

The package returns messages raw. With webklex/php-imap:

```php
use Webklex\PHPIMAP\Message;

$message = Message::fromString($pop->retrieve($number));

$message->getSubject();
$message->getFrom()->first()->mail;
$message->getMessageId();
$message->getTextBody();
```

Any RFC 5322 parser works the same way.

## Errors

Every failure is an `EduLazaro\Pop3\Exceptions\Pop3Exception`, so one `catch` handles them all. Three subclasses tell them apart:

| Exception | When |
|---|---|
| `ConnectionFailed` | The server cannot be reached, the TLS handshake fails (a certificate that does not verify), it does not greet, or it stops answering |
| `AuthenticationFailed` | The user or the password is refused. The message carries the server's words, never the password |
| `CommandFailed` | The server answers `-ERR`: a message that does not exist, or a command it does not support (`UIDL` and `TOP` are optional in the standard) |

A user or password with a line break is refused before anything is sent, so a value cannot inject commands of its own.

## Read-only on purpose

The client never sends `DELE`. POP3 keeps no read or unread mark on the server either, so reading changes nothing for the people who use the mailbox: their own mail program decides what it shows them.

One thing does matter when several readers share a POP3 mailbox: a mail program set to remove messages after downloading them deletes them for everybody. If another program reads the same mailbox, check that it leaves a copy on the server.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The protocol is tested against a scripted server. Against a real one, run [GreenMail](https://greenmail-mail-test.github.io/greenmail/) and point the tests at it:

```bash
docker run -d -p 3025:3025 -p 3110:3110 -p 3995:3995 \
    -e GREENMAIL_OPTS='-Dgreenmail.setup.test.all -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.auth.disabled' \
    greenmail/standalone
POP3_TEST_HOST=127.0.0.1 vendor/bin/phpunit
```

The CI does the same on every PHP version from 8.2.

## Sponsors

php-pop3 is supported by the following sponsors. Thank you for keeping it growing:

<p>
  <a href="https://kenodo.com"><img src="art/logo-kenodo.png" width="24" alt="Kenodo"></a>&nbsp;<a href="https://kenodo.com">Kenodo</a>&nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://andorradev.com"><img src="art/logo-andorradev.png" width="24" alt="AndorraDev"></a>&nbsp;<a href="https://andorradev.com">AndorraDev</a>
</p>

## Author

Created by [Edu Lazaro](https://edulazaro.com)

## License

php-pop3 is open-sourced software licensed under the [MIT license](LICENSE).
