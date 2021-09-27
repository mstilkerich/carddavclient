<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (c) 2020-2021 Michael Stilkerich <ms@mike2k.de>
 * Licensed under the MIT license. See COPYING file in the project root for details.
 */

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
    /** @var array<string,string> Maps the match types characters of the expected structure string to attribute value */
    private const MATCHTYPES = [
        '~' => 'contains',
        '^' => 'starts-with',
        '$' => 'ends-with',
        '=' => 'equals',
    ];

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
            'SinglePropertyParamDefined' => [
                [ 'EMAIL' => [ 'VALUE', '//' ] ],
                [ 'EMAIL' => 'p[VALUE]t~' ],
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
            $testType = $matchAll ? 'allof' : 'anyof';
            $filter = new Filter($conditions, $matchAll);
            $this->assertSame($testType, $filter->testType);
            $this->assertCount(count($expStruct), $filter->propFilters, "Number of prop filters wrong");
            $this->assertEquals(['test' => $testType], $filter->xmlAttributes());

            $struct = $expStruct;
            foreach ($filter->propFilters as $pf) {
                $prop = $pf->property;

                $this->assertArrayHasKey($prop, $struct, "Prop-Filter for unexpected property");
                $expStructPf = $struct[$prop];
                unset($struct[$prop]);

                $this->validatePropFilter($pf, $expStructPf);
            }
            $this->assertEmpty($struct, "Not all expected properties found: " . print_r($struct, true));

            // validate XML
            $this->validateFilterXml($filter);
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
            $testType = $matchAll ? 'allof' : 'anyof';
            $filter = new Filter($conditions, $matchAll);
            $this->assertSame($testType, $filter->testType);
            $this->assertEquals(['test' => $testType], $filter->xmlAttributes());

            $this->assertCount(count($expStruct), $conditions, "Expected results do not filter filter input");
            $this->assertCount(count($expStruct), $filter->propFilters, "Number of prop filters wrong");

            for ($i = 0; $i < count($filter->propFilters); ++$i) {
                $pf = $filter->propFilters[$i];
                $expStructPf = $expStruct[$i];

                $this->assertSame($conditions[$i][0], $pf->property);
                $this->validatePropFilter($pf, $expStructPf);
            }

            // validate XML
            $this->validateFilterXml($filter);
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
        /** @psalm-suppress MixedArgumentTypeCoercion Intentionally broken filters in this test */
        new Filter($conditions, true);
    }

    /**
     * Provides a list of invalid filter conditions in the simple or elaborate format, plus a substring of the expected
     * exception message.
     *
     * @return list<array{string, ?bool, string, string}>
     */
    public function textmatchProvider(): array
    {
        return [
            [ '/foo/', false, 'contains', 'foo' ],
            [ '!/$$$/', true, 'contains', '$$$' ],
            [ '!/^$/^', true, 'starts-with', '^$' ],
            [ '/foo/$', false, 'ends-with', 'foo' ],
            [ '/foo/=', false, 'equals', 'foo' ],
            [ '///=', false, 'equals', '/' ],
            [ '//', false, 'contains', '' ],
            [ '', null, '', 'Not a valid match specifier for TextMatch' ],
            [ '//+', null, '', 'Not a valid match specifier for TextMatch' ],
            [ 'x//', null, '', 'Not a valid match specifier for TextMatch' ],
        ];
    }
    /**
     * @dataProvider textmatchProvider
     *
     * @param string $pattern
     * @param ?bool  $expInv Whether inverted match is expected. Null if the pattern is erroneous and should trigger an
     *                       InvalidArgumentException. $expNeedle should contain a partial expected exception message.
     * @param string $expType The expected match type
     * @param string $expNeedle Expected search string.
     */
    public function testTextmatchPatternParsedCorrectly(
        string $pattern,
        ?bool $expInv,
        string $expType,
        string $expNeedle
    ): void {
        if (!isset($expInv)) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage($expNeedle);
        }

        $tm = new TextMatch($pattern);
        $this->assertSame($expInv, $tm->invertMatch);
        $this->assertSame($expType, $tm->matchType);
        $this->assertSame($expNeedle, $tm->needle);
    }

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

        $testType = ($expStruct[0] == "p") ? "anyof" : "allof";
        $this->assertSame($testType, $pf->testType);
        $this->assertEquals(['test' => $testType, 'name' => $pf->property], $pf->xmlAttributes());

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
                } else {
                    $this->assertSame("[", strtolower($s[0]));

                    // param-filter
                    $this->assertInstanceOf(ParamFilter::class, $c, "param filter expected");

                    $paramEnd = strpos($s, "]");
                    $this->assertIsInt($paramEnd);
                    $this->assertGreaterThan(1, $paramEnd);
                    $this->assertGreaterThan($paramEnd + 1, strlen($s));

                    // remove [param] from $s
                    $i += $paramEnd + 1;
                    $param = substr($s, 1, $paramEnd - 1);
                    $s = substr($s, $paramEnd + 1);

                    // perform checks on the filter
                    $this->assertSame($param, $c->param);
                    $i += $this->checkParamFilter($c, $s);
                }
            }
        }
    }

    /**
     * Checks a TextMatch object against the expectations described by $exp.
     * @see validatePropFilter()
     */
    private function checkTextMatch(TextMatch $tm, string $exp): void
    {
        $this->assertMatchesRegularExpression('/^[tT][$^~=]/', $exp, "text match structure desc expected");
        $this->assertSame($exp[0] == "T", $tm->invertMatch);
        $this->assertArrayHasKey($exp[1], self::MATCHTYPES, "unexpected matchtype character {$exp[1]}");
        $this->assertSame(self::MATCHTYPES[$exp[1]], $tm->matchType, "unexpected matchtype");

        $this->assertEquals(
            [
                'collation' => 'i;unicode-casemap',
                'negate-condition' => $tm->invertMatch ? 'yes' : 'no', // $tm->invertMatch already verified
                'match-type' => $tm->matchType // $tm->matchType already verified
            ],
            $tm->xmlAttributes()
        );
    }

    /**
     * Checks a ParamFilter object against the expectations described by $exp.
     * @see validatePropFilter()
     */
    private function checkParamFilter(ParamFilter $pf, string $exp): int
    {
        $this->assertEquals(['name' => $pf->param], $pf->xmlAttributes());

        $this->assertMatchesRegularExpression('/^[0tT]/', $exp, "param-filter condition char unknown $exp");
        if ($exp[0] == "0") {
            $skipChars = 1;
            $this->assertNull($pf->filter);
        } else {
            $this->assertInstanceOf(TextMatch::class, $pf->filter, "param filter cond must be textmatch");
            $this->checkTextMatch($pf->filter, $exp);
            $skipChars = 2;
        }

        return $skipChars;
    }

    /**
     * Tests the XML generated for a Filter and its children against an expected XML file.
     *
     * This function assumes that the properties and xmlAttributes() function of the filter and its children have
     * already been verified. They are used as input for the expected XML.
     */
    private function validateFilterXml(Filter $filter): void
    {
        // validate XML
        $service = new \Sabre\Xml\Service();
        $service->namespaceMap = [
            'DAV:' => 'd',
            'urn:ietf:params:xml:ns:carddav' => ''
        ];
        $xmlWriter = $service->getWriter();
        $xmlWriter->openMemory();
        $xmlWriter->startDocument();
        $xmlWriter->write([
            "name" => "filter",
            "value" => $filter,
            "attributes" => $filter->xmlAttributes()
        ]);

        $this->assertXmlStringEqualsXmlString($this->xmlFilter($filter), $xmlWriter->outputMemory());
    }

    /**
     * Creates the XML string for a filter element.
     */
    private function xmlFilter(Filter $f): string
    {
        // this is the root element for the comparison, therefore we must include the namespace definitions
        $nsdef = 'xmlns:d="DAV:" xmlns="urn:ietf:params:xml:ns:carddav"';

        $str = $this->xmlAttributesString("filter $nsdef", $f);
        foreach ($f->propFilters as $pf) {
            $str .= $this->xmlPropFilter($pf);
        }
        $str .= '</filter>';
        return $str;
    }

    /**
     * Creates the XML string for a prop-filter element.
     */
    private function xmlPropFilter(PropFilter $pf): string
    {
        $str = $this->xmlAttributesString('prop-filter', $pf);

        if (isset($pf->conditions)) {
            foreach ($pf->conditions as $c) {
                if ($c instanceof TextMatch) {
                    $str .= $this->xmlTextMatch($c);
                } else {
                    $str .= $this->xmlParamFilter($c);
                }
            }
        } else {
            $str .= $this->xmlIsNotDefined();
        }
        $str .= '</prop-filter>';

        return $str;
    }

    /**
     * Creates the XML string for a param-filter element.
     */
    private function xmlParamFilter(ParamFilter $pf): string
    {
        $str = $this->xmlAttributesString('param-filter', $pf);
        if (isset($pf->filter)) {
            $str .= $this->xmlTextMatch($pf->filter);
        } else {
            $str .= $this->xmlIsNotDefined();
        }
        $str .= "</param-filter>";
        return $str;
    }

    /**
     * Creates the XML string for a text-match element.
     */
    private function xmlTextMatch(TextMatch $tm): string
    {
        $str = '';
        if (strlen($tm->needle) > 0) {
            $str = $this->xmlAttributesString('text-match', $tm);
            $str .= "{$tm->needle}</text-match>";
        }
        return $str;
    }

    /**
     * Creates the XML string for an is-not-defined element.
     */
    private function xmlIsNotDefined(): string
    {
        return '<is-not-defined />';
    }

    /**
     * Creates the attribute string for an XML element.
     *
     * @param Filter|TextMatch|PropFilter|ParamFilter $o
     */
    private function xmlAttributesString(string $elem, $o): string
    {
        $attr = $o->xmlAttributes(); // tested separately

        $str = "<$elem";
        foreach ($attr as $name => $value) {
            $str .= " $name=\"$value\"";
        }

        $str .= '>';
        return $str;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
