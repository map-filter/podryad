<?php

namespace Tests\Unit;

use App\Support\Iin;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class IinTest extends TestCase
{
    public function test_checksum_with_first_weights(): void
    {
        $this->assertTrue(Iin::isValid('880314300158'));
        $this->assertSame(8, Iin::checksum('88031430015'));
    }

    public function test_rejects_wrong_control_digit_and_format(): void
    {
        $this->assertFalse(Iin::isValid('880314300155'));
        $this->assertFalse(Iin::isValid('88031430015'));
        $this->assertFalse(Iin::isValid('88031430015a'));
        $this->assertFalse(Iin::isValid(''));
    }

    public function test_rejects_impossible_birth_date(): void
    {
        $first = '881332300'.'01';
        $c = Iin::checksum($first);
        $this->assertNotNull($c);
        $this->assertTrue(Iin::hasValidChecksum($first.$c));
        $this->assertFalse(Iin::isValid($first.$c));
    }

    public function test_second_weight_round_is_used_when_first_gives_ten(): void
    {
        $found = null;
        for ($serial = 0; $serial < 10000 && $found === null; $serial++) {
            $first = '9005153'.str_pad((string) $serial, 4, '0', STR_PAD_LEFT);
            $digits = array_map('intval', str_split($first));
            $sum = 0;
            foreach ($digits as $i => $d) {
                $sum += $d * ($i + 1);
            }
            if ($sum % 11 === 10 && Iin::checksum($first) !== null) {
                $found = $first;
            }
        }
        $this->assertNotNull($found);
        $digits = array_map('intval', str_split($found));
        $w2 = [3, 4, 5, 6, 7, 8, 9, 10, 11, 1, 2];
        $sum = 0;
        foreach ($digits as $i => $d) {
            $sum += $d * $w2[$i];
        }
        $this->assertSame($sum % 11, Iin::checksum($found));
        $this->assertTrue(Iin::isValid($found.Iin::checksum($found)));
    }

    public function test_birth_date_and_gender(): void
    {
        $this->assertSame('1988-03-14', Iin::birthDate('880314300158')->toDateString());
        $this->assertSame('male', Iin::gender('880314300158'));
        $iin = Iin::make(CarbonImmutable::create(2001, 7, 9), false, 1234);
        $this->assertNotNull($iin);
        $this->assertSame('2001-07-09', Iin::birthDate($iin)->toDateString());
        $this->assertSame('female', Iin::gender($iin));
        $this->assertTrue(Iin::isValid($iin));
    }

    public function test_bin_validation(): void
    {
        $first = '1905400'.'1523';
        $bin = $first.Iin::checksum($first);
        $this->assertTrue(Iin::isValidBin($bin));
        $this->assertFalse(Iin::isValidBin(substr($bin, 0, 11).(((int) $bin[11] + 1) % 10)));
    }
}
