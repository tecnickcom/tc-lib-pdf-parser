<?php
/**
 * XrefStream.php
 *
 * @since       2011-05-23
 * @category    Library
 * @package     PdfParser
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2015 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * This file is part of tc-lib-pdf-parser software library.
 */

namespace Com\Tecnick\Pdf\Parser\Process;

use \Com\Tecnick\Pdf\Parser\Exception as PPException;

/**
 * Com\Tecnick\Pdf\Parser\Process\XrefStream
 *
 * Process XREF
 *
 * @since       2011-05-23
 * @category    Library
 * @package     PdfParser
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2015 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-pdf-parser
 */
abstract class XrefStream extends \Com\Tecnick\Pdf\Parser\Process\RawObject
{
    /**
     * Process object indexes
     *
     * @param array $xref
     * @param int   $obj_num
     * @param array $sdata
     */
    protected function processObjIndexes(&$xref, &$obj_num, $sdata)
    {
        foreach ($sdata as $row) {
            switch ($row[0]) {
                case 0:
                    // (f) linked list of free objects
                    break;
                case 1:
                    // (n) objects that are in use but are not compressed
                    // create unique object index: [object number]_[generation number]
                    $index = $obj_num.'_'.$row[2];
                    // check if object already exist
                    if (!isset($xref['xref'][$index])) {
                        // store object offset position
                        $xref['xref'][$index] = $row[1];
                    }
                    break;
                case 2:
                    // compressed objects
                    // $row[1] = object number of the object stream in which this object is stored
                    // $row[2] = index of this object within the object stream
                    $index = $row[1].'_0_'.$row[2];
                    $xref['xref'][$index] = -1;
                    break;
                default:
                    // null objects
                    break;
            }
            ++$obj_num;
        }
    }

    /**
     * PNG Unpredictor
     *
     * @param array $sdata
     * @param array $ddata
     * @param int   $columns
     * @param int   $prev_row
     */
    protected function pngUnpredictor($sdata, &$ddata, $columns, $prev_row)
    {
        // for each row apply PNG unpredictor
        foreach ($sdata as $key => $row) {
            // initialize new row
            $ddata[$key] = array();
            // get PNG predictor value
            $predictor = (10 + $row[0]);
            // for each byte on the row
            for ($idx = 1; $idx <= $columns; ++$idx) {
                // new index
                $jdx = ($idx - 1);
                $row_up = $prev_row[$jdx];
                if ($idx == 1) {
                    $row_left = 0;
                    $row_upleft = 0;
                } else {
                    $row_left = $row[($idx - 1)];
                    $row_upleft = $prev_row[($jdx - 1)];
                }
                switch ($predictor) {
                    case 10:
                        // PNG prediction (on encoding, PNG None on all rows)
                        $ddata[$key][$jdx] = $row[$idx];
                        break;
                    case 11:
                        // PNG prediction (on encoding, PNG Sub on all rows)
                        $ddata[$key][$jdx] = (($row[$idx] + $row_left) & 0xff);
                        break;
                    case 12:
                        // PNG prediction (on encoding, PNG Up on all rows)
                        $ddata[$key][$jdx] = (($row[$idx] + $row_up) & 0xff);
                        break;
                    case 13:
                        // PNG prediction (on encoding, PNG Average on all rows)
                        $ddata[$key][$jdx] = (($row[$idx] + (($row_left + $row_up) / 2)) & 0xff);
                        break;
                    case 14:
                        // PNG prediction (on encoding, PNG Paeth on all rows)
                        $this->minDistance($ddata, $row, $idx, $jdx, $row_left, $row_up, $row_upleft);
                        break;
                    default:
                        // PNG prediction (on encoding, PNG optimum)
                        throw new PPException('Unknown PNG predictor');
                        break;
                }
            }
            $prev_row = $ddata[$key];
        } // end for each row
    }

    /**
     * Return minimum distance for PNG unpredictor
     *
     * @param array $ddata
     * @param array $row
     * @param int   $idx
     * @param int   $jdx
     * @param int   $row_left
     * @param int   $row_up
     * @param int   $row_upleft
     */
    protected function minDistance(&$ddata, $row, $idx, $jdx, $row_left, $row_up, $row_upleft)
    {
        // initial estimate
        $pos = ($row_left + $row_up - $row_upleft);
        // distances
        $psa = abs($pos - $row_left);
        $psb = abs($pos - $row_up);
        $psc = abs($pos - $row_upleft);
        $pmin = min($psa, $psb, $psc);
        switch ($pmin) {
            case $psa:
                $ddata[$key][$jdx] = (($row[$idx] + $row_left) & 0xff);
                break;
            case $psb:
                $ddata[$key][$jdx] = (($row[$idx] + $row_up) & 0xff);
                break;
            case $psc:
                $ddata[$key][$jdx] = (($row[$idx] + $row_upleft) & 0xff);
                break;
        }
    }

    /**
     * Process XREF types
     *
     * @param array $sarr
     * @param array $xref
     * @param array $wbt
     * @param int   $index_first
     * @param int   $prevxref
     * @param int   $columns
     * @param bool  $valid_crs
     * @param bool  $filltrailer
     */
    protected function processXrefType(
        $sarr,
        &$xref,
        &$wbt,
        &$index_first,
        &$prevxref,
        &$columns,
        &$valid_crs,
        $filltrailer
    ) {
        foreach ($sarr as $key => $val) {
            if ($val[0] !== '/') {
                continue;
            }
            switch ($val[1]) {
                case 'Type':
                    $valid_crs = (($sarr[($key + 1)][0] == '/') && ($sarr[($key + 1)][1] == 'XRef'));
                    break;
                case 'Index':
                    // first object number in the subsection
                    $index_first = intval($sarr[($key + 1)][1][0][1]);
                    // number of entries in the subsection
                    // $index_entries = intval($sarr[($key + 1)][1][1][1]);
                    break;
                case 'Prev':
                    $this->processXrefPrev($sarr, $key, $prevxref);
                    break;
                case 'W':
                    // number of bytes (in the decoded stream) of the corresponding field
                    $wbt[0] = intval($sarr[($key + 1)][1][0][1]);
                    $wbt[1] = intval($sarr[($key + 1)][1][1][1]);
                    $wbt[2] = intval($sarr[($key + 1)][1][2][1]);
                    break;
                case 'DecodeParms':
                    $this->processXrefDecodeParms($sarr, $key, $columns);
                    break;
            }
            $this->processXrefTypeFt($val[1], $sarr, $xref, $filltrailer);
        }
    }

    /**
     * Process XREF type Prev
     *
     * @param array $sarr
     * @param int   $key
     * @param int   $prevxref

     */
    protected function processXrefPrev($sarr, $key, &$prevxref)
    {
        if ($sarr[($key + 1)][0] == 'numeric') {
            // get previous xref offset
            $prevxref = intval($sarr[($key + 1)][1]);
        }
    }

    /**
     * Process XREF type DecodeParms
     *
     * @param array $sarr
     * @param int   $key
     * @param int   $columns
     */
    protected function processXrefDecodeParms($sarr, $key, &$columns)
    {
        $decpar = $sarr[($key + 1)][1];
        foreach ($decpar as $kdc => $vdc) {
            if (($vdc[0] == '/') && ($vdc[1] == 'Columns') && ($decpar[($kdc + 1)][0] == 'numeric')) {
                $columns = intval($decpar[($kdc + 1)][1]);
                break;
            }
        }
    }

    /**
     * Process XREF type
     *
     * @param string $type
     * @param array  $sarr
     * @param array  $xref
     * @param bool   $filltrailer
     */
    protected function processXrefTypeFt($type, $sarr, &$xref, $filltrailer)
    {
        if (!$filltrailer) {
            return;
        }
        switch ($type) {
            case 'Size':
                if ($sarr[($key + 1)][0] == 'numeric') {
                    $xref['trailer']['size'] = $sarr[($key + 1)][1];
                }
                break;
            case 'ID':
                $xref['trailer']['id'] = array();
                $xref['trailer']['id'][0] = $sarr[($key + 1)][1][0][1];
                $xref['trailer']['id'][1] = $sarr[($key + 1)][1][1][1];
                break;
            default:
                $this->processXrefObjref($type, $sarr, $xref);
                break;
        }
    }

    /**
     * Process XREF type Objref
     *
     * @param string $type
     * @param array  $sarr
     * @param array  $xref
     */
    protected function processXrefObjref($type, $sarr, &$xref)
    {
        if (!isset($sarr[($key + 1)]) || ($sarr[($key + 1)][0] !== 'objref')) {
            return;
        }
        switch ($type) {
            case 'Root':
                $xref['trailer']['root'] = $sarr[($key + 1)][1];
                break;
            case 'Info':
                $xref['trailer']['info'] = $sarr[($key + 1)][1];
                break;
            case 'Encrypt':
                $xref['trailer']['encrypt'] = $sarr[($key + 1)][1];
                break;
        }
    }
}
