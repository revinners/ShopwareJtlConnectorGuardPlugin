<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\Values;
use Shopware\Core\Framework\Uuid\Uuid;

final class ValuesTest extends TestCase
{
    public function testSameComparesAsStringsAndIsNullAware(): void
    {
        self::assertTrue(Values::same('10009', '10009'));
        self::assertTrue(Values::same(5, '5'));
        self::assertTrue(Values::same(null, null));
        self::assertFalse(Values::same(null, ''));
        self::assertFalse(Values::same('C10009', '10009'));
    }

    public function testSameTreatsBoolsLikeMysqlTinyint(): void
    {
        self::assertTrue(Values::same(true, '1'));
        self::assertTrue(Values::same(false, '0'));
        self::assertFalse(Values::same(false, '1'));
    }

    public function testSameJsonComparesStructuresCanonically(): void
    {
        self::assertTrue(Values::sameJson(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]));
        self::assertFalse(Values::sameJson(['a' => 1], ['a' => 2]));
        self::assertTrue(Values::sameJson('note', 'note'));
        self::assertFalse(Values::sameJson('note', null));
        self::assertTrue(Values::sameJson(null, null));
    }

    public function testRenderHexesBinaryIdColumnsByNameOnly(): void
    {
        $hex = Uuid::randomHex();
        self::assertSame($hex, Values::render('customer_group_id', Uuid::fromHexToBytes($hex)));
        self::assertSame('Schröder-Wagner', Values::render('last_name', 'Schröder-Wagner'), '16 bytes but not an id column');
        self::assertNull(Values::render('title', null));
        self::assertSame('1', Values::render('active', true));
        self::assertSame('{"a":1,"b":["x"]}', Values::render('custom_fields.foo', ['a' => 1, 'b' => ['x']]));
    }

    public function testHexOrNull(): void
    {
        $hex = Uuid::randomHex();
        self::assertSame($hex, Values::hexOrNull(Uuid::fromHexToBytes($hex)));
        self::assertNull(Values::hexOrNull('short'));
        self::assertNull(Values::hexOrNull(null));
    }

    public function testDecodeJson(): void
    {
        self::assertSame(['anmerkung' => 'x'], Values::decodeJson('{"anmerkung":"x"}'));
        self::assertSame([], Values::decodeJson(null));
        self::assertSame([], Values::decodeJson('not json'));
        self::assertSame(['k' => 1], Values::decodeJson(['k' => 1]));
    }

    /**
     * F1 regression: MySQL re-serialises JSON columns (space after every "," and ":") while the
     * DAL sends compact json_encode output, so a plain-column comparison must decode both sides
     * before comparing when they are both JSON.
     */
    public function testSameStorageComparesJsonColumnsStructurally(): void
    {
        self::assertTrue(Values::sameStorage('["a","b"]', '["a", "b"]'));
        self::assertTrue(Values::sameStorage('{"x":1,"y":2}', '{"y": 2, "x": 1}'));
        self::assertFalse(Values::sameStorage('["a"]', '["b"]'));
        self::assertFalse(Values::sameStorage('[foo', '[bar'), 'two different malformed strings never compare equal');
        self::assertTrue(Values::sameStorage('abc', 'abc'));
        self::assertFalse(Values::sameStorage(null, '[]'));
        self::assertTrue(Values::sameStorage(true, '1'));
    }

    /**
     * F1 regression: CustomerStateLoader does SELECT *, so a guarded write touching password
     * (spec R5) would otherwise put the bcrypt hash into the DB audit row and the channel log.
     */
    public function testRenderRedactsSecretAndPiiColumns(): void
    {
        self::assertSame('***', Values::render('password', '$2y$10$abc'));
        self::assertSame('***', Values::render('remote_address', '10.0.0.1'));
        self::assertSame('Dr.', Values::render('title', 'Dr.'));
    }
}
