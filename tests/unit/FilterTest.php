<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient\Unit;

use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavClient\XmlElements\{Filter,PropFilter,ParamFilter,TextMatch};
use MStilkerich\Tests\CardDavClient\TestInfrastructure;

/**
 * @psalm-import-type SimpleConditions from Filter
 * @psalm-import-type ComplexConditions from Filter
 */
final class FilterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestInfrastructure::init();
    }

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public static function tearDownAfterClass(): void
    {
    }

    /**
     * Provides a list of filter conditions in the simple format.
     *
     * A filter condition in the simple format can either be
     *    - a not defined match or
     *    - a text match or
     *    - a parameter filter, which can again be
     *      - a not defined match or
     *      - a text match
     *
     * @return array<string, array{SimpleConditions, array<string,string>}>
     */
    public function simpleFilterProvider(): array
    {
        return [
            'SinglePropertyNotDefined' => [
                [ 'EMAIL' => null ],
                [ 'EMAIL' => 'p0' ],
            ],
            'SinglePropertyDefined' => [
                [ 'EMAIL' => '//' ],
                [ 'EMAIL' => 'pt~' ],
            ],
            'SinglePropertyContains' => [
                [ 'EMAIL' => '/foo@bar.de/' ],
                [ 'EMAIL' => 'pt~' ],
            ],
            'SinglePropertyNotStartsWith' => [
                [ 'FN' => '!/Mustermann/^' ],
                [ 'FN' => 'pT^' ],
            ],
            'SinglePropertyEndsWith' => [
                [ 'FN' => '/Mustermann/$' ],
                [ 'FN' => 'pt$' ],
            ],
            'SinglePropertyNotEquals' => [
                [ 'FN' => '!/Mustermann/=' ],
                [ 'FN' => 'pT=' ],
            ],
            'SinglePropertyParamNotDefined' => [
                [ 'EMAIL' => [ 'VALUE', null ] ],
                [ 'EMAIL' => 'p[VALUE]0' ],
            ],
            'SinglePropertyParamContains' => [
                [ 'EMAIL' => [ 'TYPE', '/home/' ] ],
                [ 'EMAIL' => 'p[TYPE]t~' ],
            ],
            'SinglePropertyParamNotContains' => [
                [ 'EMAIL' => [ 'TYPE', '!/home/' ] ],
                [ 'EMAIL' => 'p[TYPE]T~' ],
            ],
            'SinglePropertyParamStartsWith' => [
                [ 'ADR' => [ 'TYPE', '/work/^' ] ],
                [ 'ADR' => 'p[TYPE]t^' ],
            ],
            'TwoProperties' => [
                [ 'FN' => '/Muster/', 'EMAIL' => [ 'TYPE', null ] ],
                [ 'FN' => 'pt~', 'EMAIL' => 'p[TYPE]0' ],
            ],
        ];
    }

    /**
     * @dataProvider simpleFilterProvider
     * @param SimpleConditions $conditions
     * @param array<string,string> $expStruct
     */
    public function testSimpleFilterConditionsParsedCorrectly(array $conditions, array $expStruct): void
    {

        foreach ([true, false] as $matchAll) {
            $filter = new Filter($conditions, $matchAll);
            $this->assertSame($matchAll ? 'allof' : 'anyof', $filter->testType);
            $this->assertCount(count($expStruct), $filter->propFilters, "Number of prop filters wrong");

            $struct = $expStruct;
            foreach ($filter->propFilters as $pf) {
                $prop = $pf->property;

                $this->assertArrayHasKey($prop, $struct, "Prop-Filter for unexpected property");
                $expStructPf = $struct[$prop];
                unset($struct[$prop]);

                $this->validatePropFilter($pf, $expStructPf);
            }
            $this->assertEmpty($struct, "Not all expected properties found: " . print_r($struct, true));
        }
    }

    /**
     * Provides a list of filter conditions in the elaborate format.
     *
     * A filter condition in the elaborate format is a list of pairs of properties and filter conditions. The filter
     * conditions are a list of conditions in the simple format that shall be applied for the property, plus an optional
     * "matchAll" key with a boolean value that indicates if AND semantics should be applied.
     *    - a not defined match or
     *    - a text match or
     *    - a parameter filter, which can again be
     *      - a not defined match or
     *      - a text match
     *
     * @return array<string, array{ComplexConditions, list<string>}>
     */
    public function elaborateFilterProvider(): array
    {
        return [
            'SinglePropertyNotDefined' => [
                [ ['EMAIL', [null]] ],
                [ 'p0' ],
            ],
            'SinglePropertyDefined' => [
                [ ['EMAIL', ['//']] ],
                [ 'pt~' ],
            ],
            'SinglePropertyContains' => [
                [ ['EMAIL', ['/foo@bar.de/']] ],
                [ 'pt~' ],
            ],
            'SinglePropertyMultipleConditionsOR' => [
                [ ['EMAIL', ['!/foo/^', '/bar/', 'matchAll' => false]] ],
                [ 'pT^t~' ],
            ],
            'SinglePropertyMultipleConditionsAND' => [
                [ ['EMAIL', ['!/foo/^', '/bar/', 'matchAll' => true]] ],
                [ 'PT^t~' ],
            ],
            'MultiplePropertiesMultipleConditions' => [
                [
                    ['FN', ['/Muster/^', '/Max/', 'matchAll' => true]],
                    ['EMAIL', [['TYPE', '/work/^'], ['TYPE', null]]],
                    ['NICKNAME', [null]],
                ],
                [ 'Pt^t~', 'p[TYPE]t^[TYPE]0', 'p0' ],
            ],
        ];
    }

    /**
     * @dataProvider elaborateFilterProvider
     * @param ComplexConditions $conditions
     * @param list<string> $expStruct
     */
    public function testElaborateFilterConditionsParsedCorrectly(array $conditions, array $expStruct): void
    {
        foreach ([true, false] as $matchAll) {
            $filter = new Filter($conditions, $matchAll);
            $this->assertSame($matchAll ? 'allof' : 'anyof', $filter->testType);

            $this->assertCount(count($expStruct), $conditions, "Expected results do not filter filter input");
            $this->assertCount(count($expStruct), $filter->propFilters, "Number of prop filters wrong");

            for ($i = 0; $i < count($filter->propFilters); ++$i) {
                $pf = $filter->propFilters[$i];
                $expStructPf = $expStruct[$i];

                $this->assertSame($conditions[$i][0], $pf->property);
                $this->validatePropFilter($pf, $expStructPf);
            }
        }
    }

    /**
     * Provides a list of invalid filter conditions in the simple or elaborate format, plus a substring of the expected
     * exception message.
     *
     * @return array<string, array{array, string}>
     */
    public function invalidFilterProvider(): array
    {
        return [
            // problem with the property name
            'EmptyPropertyNameSimple' => [
                [ '' => '/foo/' ],
                'Property name must be a non-empty string',
            ],
            'EmptyPropertyNameComplex' => [
                [ ['', ['/foo/']] ],
                'Property name must be a non-empty string',
            ],
            // problem with the given conditions
            'NotDefinedAndTextMatch' => [
                [ ['EMAIL', ['/foo/', null, '/bar/']] ],
                'ONE not-defined (null) OR several match conditions',
            ],
            'SimpleArrayCondition1' => [
                [ 'EMAIL' => [ '/foo/' ] ],
                'Param filter on property EMAIL must be an element of two entries',
            ],
            'SimpleArrayCondition3' => [
                [ 'EMAIL' => [ 'TYPE', '/foo/', null ] ],
                'Param filter on property EMAIL must be an element of two entries',
            ],
            'SimpleIntCondition' => [
                [ 'EMAIL' => 5 ],
                'Invalid condition for property EMAIL',
            ],
            'SimpleObjectCondition' => [
                [ 'EMAIL' => (object) [ "/foo/" ] ],
                'Invalid condition for property EMAIL',
            ],
            'ComplexNoCond' => [
                [ ['EMAIL'] ],
                'Invalid complex condition',
            ],
            'ComplexCondArray3' => [
                [ ['EMAIL', '/foo/', '/bar/'] ],
                'Invalid complex condition',
            ],
        ];
    }

    /**
     * @dataProvider invalidFilterProvider
     * @param array $conditions
     * @param string $expErrMsg
     */
    public function testExceptionOnInvalidFilterConditions(array $conditions, string $expErrMsg): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expErrMsg);
        new Filter($conditions, true);
    }

    /** @var array<string,string> Maps the match types characters of the expected structure string to attribute value */
    private const MATCHTYPES = [
        '~' => 'contains',
        '^' => 'starts-with',
        '$' => 'ends-with',
        '=' => 'equals',
    ];

    /**
     * Validates the structure and basic properties of a prop-filter element.
     *
     * The expected structure is described by a string, where each character signifies the type including
     * basic properties of an element. The characters and their meaning are:
     *   p/P = prop-filter element with anyof/allof filter semantics for its contained conditions
     *   t/T = text-match element with invert semantics not set/set.
     *   =/^/$/~ = match type of the preceding text element (equals/starts-with/ends-with/contains)
     *   [TYPE] = param-filter for the parameter TYPE
     *   0 = check that preceding parameter or property is not defined
     */
    private function validatePropFilter(PropFilter $pf, string $expStruct): void
    {
        $this->assertMatchesRegularExpression('/^[pP]./', $expStruct, "prop-filter match type expected");
        $this->assertSame(($expStruct[0] == "p") ? "anyof" : "allof", $pf->testType);

        if ($expStruct[1] == "0") {
            $this->assertNull($pf->conditions, "Conditions expected to be null");
            $this->assertSame(2, strlen($expStruct), "No further elements expected in structure string");
        } else {
            $this->assertIsArray($pf->conditions);
            // process all the conditions of the filter
            $i = 1;
            foreach ($pf->conditions as $c) {
                $s = substr($expStruct, $i);
                $this->assertMatchesRegularExpression('/^[tT[]/', $s, "prop-filter condition char unknown $s");

                if (strtolower($s[0]) == "t") {
                    // text match
                    $this->assertInstanceOf(TextMatch::class, $c, "text match expected");
                    $this->checkTextMatch($c, $s);
                    $i += 2;
                } elseif (strtolower($s[0]) == "[") {
                    // param-filter
                    $this->assertInstanceOf(ParamFilter::class, $c, "param filter expected");
                    $paramEnd = strpos($s, "]");

                    $this->assertIsInt($paramEnd);
                    $i += $paramEnd + 1;
                    $this->assertGreaterThan(1, $paramEnd);
                    $param = substr($s, 1, $paramEnd - 1);
                    $this->assertSame($param, $c->param);
                    $this->assertGreaterThan($paramEnd + 1, strlen($s));
                    $s = substr($s, $paramEnd + 1);

                    $this->assertMatchesRegularExpression('/^[0tT]/', $s, "param-filter condition char unknown $s");
                    if ($s[0] == "0") {
                        ++$i;
                        $this->assertNull($c->filter);
                    } else {
                        $this->assertInstanceOf(TextMatch::class, $c->filter, "param filter cond must be textmatch");
                        $this->checkTextMatch($c->filter, $s);
                        $i += 2;
                    }
                }
            }
        }
    }

    private function checkTextMatch(TextMatch $tm, string $exp): void
    {
        $this->assertMatchesRegularExpression('/^[tT][$^~=]/', $exp, "text match structure desc expected");
        $this->assertSame($exp[0] == "T", $tm->invertMatch);
        $this->assertArrayHasKey($exp[1], self::MATCHTYPES, "unexpected matchtype character {$exp[1]}");
        $this->assertSame(self::MATCHTYPES[$exp[1]], $tm->matchType, "unexpected matchtype");
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
