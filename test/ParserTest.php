<?php

/**
 * ParserTest.php
 *
 * @since       2011-05-23
 * @category    Library
 * @package     Pdfparser
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2017 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * This file is part of tc-lib-pdf-parser software library.
 */

namespace Test;

use PHPUnit\Framework\TestCase;

/**
 * Filter Test
 *
 * @since       2011-05-23
 * @category    Library
 * @package     PdfParser
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2017 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-pdf-parser
 */
class ParserTest extends TestCase
{
    /**
     * @dataProvider getParseProvider
     */
    public function testParse($filename, $hash)
    {
        $cfg = array('ignore_filter_errors' => true);
        $rawdata = file_get_contents($filename);
        $testObj = new \Com\Tecnick\Pdf\Parser\Parser($cfg);
        $data = $testObj->parse($rawdata);
        $this->assertEquals($hash, md5(serialize($data)));
    }

    public static function getParseProvider()
    {
        return array(
            array('resources/test/example_005.pdf', 'b65259e9c2864e707b10495e64c71363'),
            array('resources/test/example_036.pdf', 'f707a4503fba04b79a1c3905af9d4fbc'),
            array('resources/test/example_046.pdf', '3b65bf473a50da304cc9549d18bfec73'),
        );
    }
}
