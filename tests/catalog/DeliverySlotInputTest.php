<?php
declare(strict_types=1);

use Foodwell\Validation\DeliverySlotInput;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeliverySlotInput, the class that checks a delivery slot's
 * JSON body (#39) before anything reaches the database (#42).
 *
 * No database or HTTP server is involved: we call validate() directly.
 * Most times are built relative to "now", so the tests keep working on any day.
 */
final class DeliverySlotInputTest extends TestCase
{
    /** The UTC time this many hours from now, on the hour, e.g. 2026-11-20T19:00:00Z. */
    private static function hoursAhead(int $hours): string
    {
        return (new DateTimeImmutable(sprintf('%+d hours', $hours), new DateTimeZone('UTC')))->format('Y-m-d\TH:00:00\Z');
    }

    /** A body that passes: a 2-hour window two days from now. */
    private static function valid(): array
    {
        return ['startsAt' => self::hoursAhead(48), 'endsAt' => self::hoursAhead(50), 'capacity' => 4];
    }

    public function testAValidBodyPassesAndTimesAreReadyForTheDatabase(): void
    {
        $body = ['startsAt' => '2027-01-15T19:00:00Z', 'endsAt' => '2027-01-15T21:00:00Z', 'capacity' => 4, 'isActive' => false];
        $result = DeliverySlotInput::validate($body);

        self::assertSame([], $result['errors']);
        // UTC, in MySQL's DATETIME format, ready for the INSERT.
        self::assertSame(['startsAt' => '2027-01-15 19:00:00', 'endsAt' => '2027-01-15 21:00:00',
            'capacity' => 4, 'isActive' => false], $result['data']);
    }

    /**
     * The same moment written different ways must all end up as the same UTC time.
     *
     * @dataProvider sameMoment
     */
    public function testTimeZonesAreConvertedToUtc(string $written): void
    {
        $result = DeliverySlotInput::validate(['startsAt' => $written, 'endsAt' => '2027-01-15T21:00:00Z', 'capacity' => 1]);

        self::assertSame([], $result['errors']);
        self::assertSame('2027-01-15 19:00:00', $result['data']['startsAt']);
    }

    public static function sameMoment(): array
    {
        return [
            'UTC with Z' => ['2027-01-15T19:00:00Z'],
            'UTC without seconds' => ['2027-01-15T19:00Z'],
            'UTC as +00:00' => ['2027-01-15T19:00:00+00:00'],
            'Montreal in winter (-05:00)' => ['2027-01-15T14:00:00-05:00'],
            'Montreal without seconds' => ['2027-01-15T14:00-05:00'],
            'Paris in winter (+01:00)' => ['2027-01-15T20:00:00+01:00'],
        ];
    }

    /**
     * Each row: a change to the valid body, and the field(s) that must be reported.
     *
     * @dataProvider invalidBodies
     */
    public function testEachProblemIsReportedByName(array $change, array $fields): void
    {
        $result = DeliverySlotInput::validate(array_merge(self::valid(), $change));

        $reported = array_keys($result['errors']);
        sort($reported);
        sort($fields);
        self::assertSame($fields, $reported);
    }

    public static function invalidBodies(): array
    {
        return [
            'no time zone' => [['startsAt' => '2027-01-15T19:00:00'], ['startsAt']],
            'a date without a time' => [['startsAt' => '2027-01-15'], ['startsAt']],
            'a space instead of T' => [['startsAt' => '2027-01-15 19:00:00Z'], ['startsAt']],
            'a number instead of text' => [['startsAt' => 1800000000], ['startsAt']],
            // PHP would quietly roll these over into another date; they must be refused.
            'February 30' => [['startsAt' => '2027-02-30T19:00:00Z', 'endsAt' => '2027-02-30T21:00:00Z'], ['startsAt', 'endsAt']],
            'hour 25' => [['startsAt' => '2027-01-15T25:00:00Z'], ['startsAt']],
            'minute 61' => [['endsAt' => '2027-01-15T21:61:00Z'], ['endsAt']],
            'end before start' => [['startsAt' => self::hoursAhead(50), 'endsAt' => self::hoursAhead(48)], ['endsAt']],
            'end equal to start' => [['startsAt' => self::hoursAhead(50), 'endsAt' => self::hoursAhead(50)], ['endsAt']],
            '13-hour window' => [['startsAt' => self::hoursAhead(48), 'endsAt' => self::hoursAhead(61)], ['endsAt']],
            'over a year ahead' => [['startsAt' => self::hoursAhead(24 * 366), 'endsAt' => self::hoursAhead(24 * 366 + 1)], ['startsAt']],
            'capacity 0' => [['capacity' => 0], ['capacity']],
            'capacity 1001' => [['capacity' => 1001], ['capacity']],
            'capacity as text' => [['capacity' => '4'], ['capacity']],
            'capacity 2.0' => [['capacity' => 2.0], ['capacity']],
            'capacity true' => [['capacity' => true], ['capacity']],
            'isActive as text' => [['isActive' => 'true'], ['isActive']],
            'an unknown field' => [['availableCapacity' => 4], ['availableCapacity']],
        ];
    }

    public function testMissingFieldsAreAllReported(): void
    {
        $fields = array_keys(DeliverySlotInput::validate([])['errors']);
        sort($fields);

        self::assertSame(['capacity', 'endsAt', 'startsAt'], $fields);
    }

    public function testLimitsAreInclusive(): void
    {
        // Exactly 12 hours and capacities 1 and 1000 are allowed.
        $twelveHours = ['startsAt' => self::hoursAhead(48), 'endsAt' => self::hoursAhead(60)];
        self::assertSame([], DeliverySlotInput::validate($twelveHours + ['capacity' => 1])['errors']);
        self::assertSame([], DeliverySlotInput::validate($twelveHours + ['capacity' => 1000])['errors']);
    }

    public function testValidateDoesNotDecideWhetherAPastStartIsAllowed(): void
    {
        // POST refuses a past start, but PUT allows editing a past slot whose
        // window doesn't move. So validate() leaves that rule to the
        // controller, and isFuture() is the helper the controller uses.
        $past = ['startsAt' => self::hoursAhead(-5), 'endsAt' => self::hoursAhead(-3), 'capacity' => 1];

        self::assertSame([], DeliverySlotInput::validate($past)['errors']);
        self::assertFalse(DeliverySlotInput::isFuture(DeliverySlotInput::validate($past)['data']['startsAt']));
        self::assertTrue(DeliverySlotInput::isFuture(DeliverySlotInput::validate(self::valid())['data']['startsAt']));
    }

    public function testIsActiveIsOnlyInDataWhenSent(): void
    {
        // Left out = the controller decides (true on create, keep on update).
        self::assertArrayNotHasKey('isActive', DeliverySlotInput::validate(self::valid())['data']);
        self::assertTrue(DeliverySlotInput::validate(self::valid() + ['isActive' => true])['data']['isActive']);
    }
}
