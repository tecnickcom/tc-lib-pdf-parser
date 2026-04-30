<?php

/**
 * ParserTest.php
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

use Com\Tecnick\Pdf\Parser\Parser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Filter Test
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfParser
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-parser
 */
class ParserTest extends TestCase
{
    #[DataProvider('getParseProvider')]
    public function testParse(string $filename, string $hash): void
    {
        $cfg = [
            'ignore_filter_errors' => true,
        ];
        $rawdata = \file_get_contents($filename);
        $this->assertNotFalse($rawdata);
        $parser = new Parser($cfg);
        $data = $parser->parse($rawdata);
        $this->assertEquals($hash, \md5(\serialize($data)));
    }

    /**
     * @return array<int, array{0:string, 1:string}>
     */
    public static function getParseProvider(): array
    {
        return [
            ['resources/test/example_005.pdf', '510a5ea860470dae0781f4bd8d5eb250'],
            ['resources/test/example_036.pdf', '2869501cf41a4c4a0402c00832329e25'],
            ['resources/test/example_046.pdf', 'cfaee514b9c09aa282b4e2a8f0061a3d'],
        ];
    }
}
