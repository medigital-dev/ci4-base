<?php

namespace MedigitalDev\Ci4Base\Tests\Model;

use MedigitalDev\Ci4Base\Models\BaseModel;
use PHPUnit\Framework\TestCase;

final class BaseModelUuidTest extends TestCase
{
    /**
     * Pola UUID v4 standar: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     * dengan versi selalu '4' dan varian selalu 8/9/a/b.
     */
    private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function testGenerateUuidReturnsValidUuidV4Format(): void
    {
        $uuid = model(BaseModel::class)->generateUuidV4();

        $this->assertMatchesRegularExpression(self::UUID_V4_PATTERN, $uuid);
    }

    public function testGenerateUuidReturnsUniqueValues(): void
    {
        $uuids = [];

        for ($i = 0; $i < 1000; $i++) {
            $uuids[] = model(BaseModel::class)->generateUuidV4();
        }

        // kalau ada duplikat, count setelah array_unique akan lebih kecil
        $this->assertCount(1000, array_unique($uuids));
    }

    public function testGenerateUuidHasCorrectLength(): void
    {
        $uuid = model(BaseModel::class)->generateUuidV4();

        // 32 karakter hex + 4 tanda hubung = 36 karakter
        $this->assertSame(36, strlen($uuid));
    }
}
