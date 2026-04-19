<?php

/**
 * XrefTest.php
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

class XrefTest extends TestCase
{
    public function testProcessObjIndexesHandlesInUseAndCompressedObjects(): void
    {
        $xref = [
            'trailer' => [
                'id' => [],
                'info' => '',
                'root' => '',
                'size' => 0,
            ],
            'xref' => [],
        ];
        $obj_num = 3;
        $sdata = [
            [1, 42, 0],
            [2, 7, 4],
            [0, 0, 0],
        ];

        $parser = new XrefStreamHarness();
        $parser->processObjIndexesPublic($xref, $obj_num, $sdata);

        $this->assertSame(6, $obj_num);
        $this->assertSame(42, $xref['xref']['3_0']);
        $this->assertSame(-1, $xref['xref']['7_0_4']);
    }

    public function testPngUnpredictorDecodesAndRejectsUnknownPredictor(): void
    {
        $parser = new XrefStreamHarness();

        $decoded = [];
        $parser->pngUnpredictorPublic([[0, 10]], $decoded, 1, [0]);
        $this->assertSame(10, $decoded[0][0]);

        $decoded = [];
        $parser->pngUnpredictorPublic([[1, 5]], $decoded, 1, [0]);
        $this->assertSame(5, $decoded[0][0]);

        $decoded = [];
        $parser->pngUnpredictorPublic([[2, 3]], $decoded, 1, [4]);
        $this->assertSame(7, $decoded[0][0]);

        $decoded = [];
        $parser->pngUnpredictorPublic([[4, 8]], $decoded, 1, [1]);
        $this->assertSame(9, $decoded[0][0]);

        $this->expectException(PPException::class);
        $this->expectExceptionMessage('Unknownn PNG predictor');
        $decoded = [];
        $parser->pngUnpredictorPublic([[9, 1]], $decoded, 1, [0]);
    }

    public function testDecodeXrefParsesEntriesAndTrailer(): void
    {
        $pdf =
            "xref\r\n"
            . "0 2\r\n"
            . "0000000000 65535 f\r\n"
            . "0000000017 00000 n\r\n"
            . "trailer << /Size 2 /Root 1 0 R /Info 2 0 R /ID [<AA><BB>] >>\r\n";

        $parser = new XrefHarness();
        $parser->setPdfDataPublic($pdf);
        $xref = $parser->decodeXrefPublic(0, ['xref' => []]);

        $this->assertSame(17, $xref['xref']['1_0']);
        $this->assertSame(2, $xref['trailer']['size']);
        $this->assertSame('1_0', $xref['trailer']['root']);
        $this->assertSame('2_0', $xref['trailer']['info']);
        $this->assertSame(['AA', 'BB'], $xref['trailer']['id']);
    }

    public function testDecodeXrefStreamParsesRowsAndTrailer(): void
    {
        $stream_data = \pack('C*', 0, 1, 10, 0, 0, 2, 5, 1);

        $parser = new XrefHarness();
        $parser->setStubRawObject(['objref', '5_0', 0]);
        $parser->setStubIndirectObject([
            [
                '<<',
                [
                    ['/', 'Type', 0],
                    ['/', 'XRef', 0],
                    ['/', 'W', 0],
                    ['[', [['numeric', '1', 0], ['numeric', '1', 0], ['numeric', '1', 0]], 0],
                    ['/', 'Index', 0],
                    ['[', [['numeric', '0', 0], ['numeric', '2', 0]], 0],
                    ['/', 'Size', 0],
                    ['numeric', '2', 0],
                    ['/', 'Root', 0],
                    ['objref', '1_0', 0],
                    ['/', 'ID', 0],
                    ['[', [['hex', 'AA', 0], ['hex', 'BB', 0]], 0],
                    ['/', 'DecodeParms', 0],
                    ['<<', [['/', 'Columns', 0], ['numeric', '3', 0]], 0],
                ],
                0,
            ],
            ['stream', $stream_data, 0, [$stream_data, []]],
        ]);

        $xref = $parser->decodeXrefStreamPublic(100, ['xref' => []]);

        $this->assertSame(10, $xref['xref']['0_0']);
        $this->assertSame(-1, $xref['xref']['5_0_1']);
        $this->assertSame('1_0', $xref['trailer']['root']);
        $this->assertSame(2, $xref['trailer']['size']);
        $this->assertSame(['AA', 'BB'], $xref['trailer']['id']);
    }

    public function testProcessDdataUsesDefaultTypeWhenFirstFieldWidthIsZero(): void
    {
        $parser = new XrefHarness();
        $sdata = [];
        $parser->processDdataPublic($sdata, [[9, 3]], [0, 1, 1]);

        $this->assertSame([1, 9, 3], $sdata[0]);
    }

    public function testGetXrefDataThrowsWhenStartxrefIsMissing(): void
    {
        $parser = new XrefHarness();
        $parser->setPdfDataPublic("%PDF-1.7\nNo xref markers here");

        $this->expectException(PPException::class);
        $this->expectExceptionMessage('Unable to find startxref (1)');
        $parser->getXrefDataPublic();
    }
}
