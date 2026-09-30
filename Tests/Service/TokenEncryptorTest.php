<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Tests\Service;

use KimaiPlugin\GoogleCalendarBundle\Service\TokenEncryptor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \KimaiPlugin\GoogleCalendarBundle\Service\TokenEncryptor
 */
class TokenEncryptorTest extends TestCase
{
    public function testRoundTripUsesRandomNonce(): void
    {
        $encryptor = new TokenEncryptor('secret');

        $first = $encryptor->encrypt('token');
        $second = $encryptor->encrypt('token');

        self::assertStringStartsWith('enc1:', $first);
        self::assertNotSame($first, $second);
        self::assertStringNotContainsString('token', $first);
        self::assertSame('token', $encryptor->decrypt($first));
        self::assertSame('token', $encryptor->decrypt($second));
    }

    public function testEmptyValuesArePassedThrough(): void
    {
        $encryptor = new TokenEncryptor('secret');

        self::assertNull($encryptor->encrypt(null));
        self::assertSame('', $encryptor->encrypt(''));
        self::assertNull($encryptor->decrypt(null));
        self::assertSame('', $encryptor->decrypt(''));
    }

    public function testUnencryptedValuesAreReturnedAsIs(): void
    {
        self::assertSame('plain', (new TokenEncryptor('secret'))->decrypt('plain'));
    }

    public function testInvalidOrForeignCipherTextReturnsNull(): void
    {
        $cipher = (new TokenEncryptor('secret'))->encrypt('token');

        self::assertNull((new TokenEncryptor('another secret'))->decrypt($cipher));
        self::assertNull((new TokenEncryptor('secret'))->decrypt('enc1:not-base64!'));
        self::assertNull((new TokenEncryptor('secret'))->decrypt('enc1:' . base64_encode('short')));
    }
}
