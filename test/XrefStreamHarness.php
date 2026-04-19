<?php

/**
 * XrefStreamHarness.php
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

use Com\Tecnick\Pdf\Parser\Process\XrefStream;

class XrefStreamHarness extends XrefStream
{
    /**
     * @param array{
     *        'trailer': array{
     *            'encrypt'?: string,
     *            'id': array<int, string>,
     *            'info': string,
     *            'root': string,
     *            'size': int,
     *        },
     *        'xref': array<string, int>,
     *    } $xref
     * @param array<int, array<int, int>> $sdata
     */
    public function processObjIndexesPublic(array &$xref, int &$obj_num, array $sdata): void
    {
        $this->processObjIndexes($xref, $obj_num, $sdata);
    }

    /**
     * @param array<int, array<int, int>> $sdata
     * @param array<int, array<int, int>> $ddata
     * @param array<int, int>             $prev_row
     */
    public function pngUnpredictorPublic(array $sdata, array &$ddata, int $columns, array $prev_row): void
    {
        $this->pngUnpredictor($sdata, $ddata, $columns, $prev_row);
    }
}
