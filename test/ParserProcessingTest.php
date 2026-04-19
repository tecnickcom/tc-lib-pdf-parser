<?php

/**
 * ParserProcessingTest.php
 *
 * @since     2011-05-23
 * @category  Library
 * @package   Pdfparser
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * This file is part of tc-lib-pdf-parser software library.
 */

namespace Test;

use Com\Tecnick\Pdf\Parser\Exception as PPException;
use PHPUnit\Framework\TestCase;

class ParserProcessingTest extends TestCase
{
    public function testParseRejectsEmptyAndInvalidData(): void
    {
        $parser = new ParserHarness();

        $this->expectException(PPException::class);
        $this->expectExceptionMessage('Empty PDF data.');
        $parser->parse('');
    }

    public function testParseRejectsDataWithoutPdfHeader(): void
    {
        $parser = new ParserHarness();

        $this->expectException(PPException::class);
        $this->expectExceptionMessage('Invalid PDF data: missing %PDF header.');
        $parser->parse('not a pdf');
    }

    public function testParseLoadsPositiveOffsetsOnlyAndClearsPdfData(): void
    {
        $parser = new ParserHarness();
        $parser->setStubXrefData([
            'trailer' => [
                'id' => [],
                'info' => '',
                'root' => '1_0',
                'size' => 3,
            ],
            'xref' => [
                '1_0' => 10,
                '2_0' => 0,
                '3_0' => -1,
            ],
        ]);
        $parser->setStubIndirectReturn([['numeric', '42', 0]]);

        $parsed = $parser->parse("junk%PDF-1.7\n");

        $calls = $parser->getIndirectCalls();
        $this->assertCount(1, $calls);
        $this->assertSame(['1_0', 10, true], $calls[0]);
        $this->assertSame('', $parser->getPdfDataPublic());
        $this->assertArrayHasKey('1_0', $parsed[1]);
        $this->assertArrayNotHasKey('2_0', $parsed[1]);
        $this->assertArrayNotHasKey('3_0', $parsed[1]);
    }

    public function testParentIndirectObjectRejectsInvalidReference(): void
    {
        $parser = new ParserHarness();
        $parser->setPdfDataPublic("%PDF-1.7\n1 0 obj\nendobj\n");

        $this->expectException(PPException::class);
        $this->expectExceptionMessage('Invalid object reference:');
        $parser->callParentGetIndirectObject('invalid', 0, true);
    }

    public function testParentIndirectObjectReturnsEmptyResultWhenTargetIsMissing(): void
    {
        $parser = new ParserHarness();
        $parser->setPdfDataPublic("%PDF-1.7\n");

        $obj = $parser->callParentGetIndirectObject('1_0', 0, true);

        $this->assertSame([], $obj);
    }

    public function testRawIndirectObjectDecodesStreamWhenDictionaryIsPresent(): void
    {
        $parser = new ParserHarness();
        $parser->setRawObjectQueue([
            ['<<', [['/', 'Length', 0], ['numeric', '3', 0]], 5],
            ['stream', "abcdef", 12],
            ['endobj', 'endobj', 18],
        ]);

        $objdata = $parser->getRawIndirectObjectPublic(0, true);

        $this->assertCount(2, $objdata);
        $this->assertSame('stream', $objdata[1][0]);
        $this->assertSame('abcdef', $objdata[1][1]);
        if (!isset($objdata[1][3]) || !\is_array($objdata[1][3])) {
            $this->fail('Decoded stream payload not available.');
        }

        /** @var array{0:string,1:array<string>} $decoded */
        $decoded = $objdata[1][3];
        $this->assertSame('abc', $decoded[0]);
        $this->assertSame([], $decoded[1]);
    }

    public function testGetFiltersParsesSingleAndArraySyntax(): void
    {
        $parser = new ParserHarness();

        $single = [
            ['/', 'Filter', 0],
            ['/', 'FlateDecode', 0],
        ];
        $filters = $parser->getFiltersPublic([], $single, 0);
        $this->assertSame(['FlateDecode'], $filters);

        $list = [
            ['/', 'Filter', 0],
            ['[', [['/', 'FlateDecode', 0], ['numeric', '1', 0], ['/', 'ASCIIHexDecode', 0]], 0],
        ];
        $filters = $parser->getFiltersPublic([], $list, 0);
        $this->assertSame(['FlateDecode', 'ASCIIHexDecode'], $filters);
    }

    public function testGetObjectValResolvesCachedAndMappedObjectReferences(): void
    {
        $parser = new ParserHarness();
        $parser->setObjectsPublic([
            '3_0' => [['string', 'cached', 0]],
        ]);
        $cached = $parser->getObjectValPublic(['objref', '3_0', 0]);
        $this->assertSame(['string', 'cached', 0], $cached);

        $parser = new ParserHarness();
        $parser->setXrefMapPublic(['4_0' => 99]);
        $parser->setStubIndirectReturn([['string', 'loaded', 0]]);
        $loaded = $parser->getObjectValPublic(['objref', '4_0', 0]);
        $this->assertSame(['string', 'loaded', 0], $loaded);
    }

    public function testGetDecodedStreamTracksErrorsWhenConfiguredToIgnore(): void
    {
        $parser = new ParserHarness(['ignore_filter_errors' => true]);

        $result = $parser->getDecodedStreamPublic(['UnknownFilter'], 'sample-data');

        $this->assertSame('sample-data', $result[0]);
        $this->assertSame(['UnknownFilter'], $result[1]);
    }

    public function testGetDecodedStreamThrowsWhenFilterErrorsAreNotIgnored(): void
    {
        $parser = new ParserHarness();

        $this->expectException(PPException::class);
        $parser->getDecodedStreamPublic(['UnknownFilter'], 'sample-data');
    }
}
