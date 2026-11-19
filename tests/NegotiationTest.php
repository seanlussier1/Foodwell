<?php
declare(strict_types=1);

use Foodwell\Http\Negotiation;
use PHPUnit\Framework\TestCase;

final class NegotiationTest extends TestCase
{
    public function testAcceptedAliasIsNotExcludedByAnotherMediaType(): void
    {
        $formats = [Negotiation::JSON, Negotiation::XML, Negotiation::YAML];
        self::assertSame('text/xml', Negotiation::choose('application/xml;q=0, text/xml;q=1', $formats));
        self::assertSame('text/yaml', Negotiation::choose('application/yaml;q=0, text/yaml;q=1', $formats));
        self::assertSame('application/xml', Negotiation::choose('text/xml;q=0, application/xml;q=1', $formats));
        self::assertSame('application/xml', Negotiation::format('text/xml'));
        self::assertSame('application/yaml', Negotiation::format('text/yaml'));
    }

    public function testSpecificExclusionOverridesWildcardForThatMediaType(): void
    {
        self::assertSame('text/xml', Negotiation::choose('application/xml;q=0, text/*;q=0.8', [Negotiation::XML]));
        self::assertNull(Negotiation::choose('application/xml;q=0', [Negotiation::XML]));
    }
}
