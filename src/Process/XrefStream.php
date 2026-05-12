<?php

declare(strict_types=1);

/**
 * XrefStream.php
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfParser
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * This file is part of tc-lib-pdf-parser software library.
 */

namespace Com\Tecnick\Pdf\Parser\Process;

use Com\Tecnick\Pdf\Parser\Exception as PPException;

/**
 * Com\Tecnick\Pdf\Parser\Process\XrefStream
 *
 * Process XREF
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfParser
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * @phpstan-import-type RawObjectArray from \Com\Tecnick\Pdf\Parser\Process\RawObject
 *
 * @phpstan-type XrefData array{
 *                 'trailer': array{
 *                     'encrypt'?: string,
 *                     'id': array<int, string>,
 *                     'info': string,
 *                     'root': string,
 *                     'size': int,
 *                 },
 *                 'xref': array<string, int>,
 *             }
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
abstract class XrefStream extends \Com\Tecnick\Pdf\Parser\Process\RawObject
{
    /**
     * Process object indexes
     *
     * @param XrefData                   $xref    XREF data
     * @param int                        $obj_num Object number
     * @param array<int, array<int, int>> $sdata   Stream data
     */
    protected function processObjIndexes(array &$xref, int &$obj_num, array $sdata): void
    {
        foreach ($sdata as $sdatum) {
            $entryType = (int) ($sdatum[0] ?? 0);
            switch ($entryType) {
                case 0:
                    // (f) linked list of free objects
                    break;
                case 1:
                    // (n) objects that are in use but are not compressed
                    // create unique object index: [object number]_[generation number]
                    $index = $obj_num . '_' . (int) ($sdatum[2] ?? 0);
                    // check if object already exist
                    if (!\array_key_exists($index, $xref['xref'])) {
                        // store object offset position
                        $xref['xref'][$index] = (int) ($sdatum[1] ?? 0);
                    }

                    break;
                case 2:
                    // compressed objects
                    // $row[1] = object number of the object stream in which this object is stored
                    // $row[2] = index of this object within the object stream
                    $index = (int) ($sdatum[1] ?? 0) . '_0_' . (int) ($sdatum[2] ?? 0);
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
     * @param array<int, array<int, int>> $sdata    Stream data
     * @param array<int, array<int, int>> $ddata    Decoded data
     * @param int                         $columns  Number of columns
     * @param array<int, int>             $prev_row Previous row
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    protected function pngUnpredictor(array $sdata, array &$ddata, int $columns, array $prev_row): void
    {
        // for each row apply PNG unpredictor
        foreach ($sdata as $key => $row) {
            // initialize new row
            $filllen = \max(0, $columns);
            $ddata[$key] = \array_fill(0, $filllen, 0);
            // get PNG predictor value
            $predictor = 10 + (int) ($row[0] ?? 0);
            // for each byte on the row
            for ($idx = 1; $idx <= $columns; ++$idx) {
                // new index
                $jdx = $idx - 1;
                $row_up = (int) ($prev_row[$jdx] ?? 0);
                if ($idx === 1) {
                    $row_left = 0;
                    $row_upleft = 0;
                } else {
                    $row_left = (int) ($row[$idx - 1] ?? 0);
                    $row_upleft = (int) ($prev_row[$jdx - 1] ?? 0);
                }

                $row_value = (int) ($row[$idx] ?? 0);

                switch ($predictor) {
                    case 10:
                        // PNG prediction (on encoding, PNG None on all rows)
                        $ddata[$key][$jdx] = $row_value;
                        break;
                    case 11:
                        // PNG prediction (on encoding, PNG Sub on all rows)
                        $ddata[$key][$jdx] = ($row_value + $row_left) & 0xff;
                        break;
                    case 12:
                        // PNG prediction (on encoding, PNG Up on all rows)
                        $ddata[$key][$jdx] = ($row_value + $row_up) & 0xff;
                        break;
                    case 13:
                        // PNG prediction (on encoding, PNG Average on all rows)
                        $ddata[$key][$jdx] = ($row_value + \intdiv($row_left + $row_up, 2)) & 0xff;
                        break;
                    case 14:
                        // PNG prediction (on encoding, PNG Paeth on all rows)
                        $this->minDistance($ddata, $key, $row_value, $jdx, [$row_left, $row_up, $row_upleft]);
                        break;
                    default:
                        // PNG prediction (on encoding, PNG optimum)
                        throw new PPException('Unknownn PNG predictor');
                }
            }

            $prev_row = $ddata[$key];
        } // end for each row
    }

    /**
     * Return minimum distance for PNG unpredictor
     *
     * @param array<int, array<int, int>> $ddata      Decoded data
     * @param int                         $key        Key
     * @param int                         $row_value  Current row value
     * @param int                         $jdx        Jdx
     * @param array{0:int, 1:int, 2:int}  $rows       Left, up and up-left row values
     */
    protected function minDistance(array &$ddata, int $key, int $row_value, int $jdx, array $rows): void
    {
        $row_left = $rows[0];
        $row_up = $rows[1];
        $row_upleft = $rows[2];

        // initial estimate
        $pos = $row_left + $row_up - $row_upleft;
        // distances
        $psa = \abs($pos - $row_left);
        $psb = \abs($pos - $row_up);
        $psc = \abs($pos - $row_upleft);
        $pmin = \min($psa, $psb, $psc);
        switch ($pmin) {
            case $psa:
                $ddata[$key][$jdx] = ($row_value + $row_left) & 0xff;
                break;
            case $psb:
                $ddata[$key][$jdx] = ($row_value + $row_up) & 0xff;
                break;
            case $psc:
                $ddata[$key][$jdx] = ($row_value + $row_upleft) & 0xff;
                break;
        }
    }

    /**
     * Process XREF types
     *
     * @param array<int, RawObjectArray> $sarr        Stream data
     * @param XrefData                   $xref        XREF data
     * @param array<int, int>            $wbt         WBT data
     * @param array{index_first: int|null, prevxref: int|null, columns: int, valid_crs: bool} $state Parsing state
     * @param bool                       $filltrailer Fill trailer
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    protected function processXrefType(array $sarr, array &$xref, array &$wbt, array &$state, bool $filltrailer): void
    {
        foreach ($sarr as $key => $val) {
            if ($val[0] !== '/') {
                continue;
            }

            if (!\is_string($val[1])) {
                continue;
            }

            $next = $sarr[$key + 1] ?? null;

            switch ($val[1]) {
                case 'Type':
                    $state['valid_crs'] = \is_array($next) && $next[0] === '/' && $next[1] === 'XRef';
                    break;
                case 'Index':
                    // first object number in the subsection
                    $indexFirst = $next[1][0][1] ?? null;
                    if (\is_array($next) && \is_scalar($indexFirst)) {
                        $state['index_first'] = (int) $indexFirst;
                    }
                    // number of entries in the subsection
                    // $index_entries = \intval($sarr[($key + 1)][1][1][1]);
                    break;
                case 'Prev':
                    $this->processXrefPrev($next, $state['prevxref']);
                    break;
                case 'W':
                    // number of bytes (in the decoded stream) of the corresponding field
                    if (\is_array($next)) {
                        $wbt[0] = (int) ($next[1][0][1] ?? 0);
                        $wbt[1] = (int) ($next[1][1][1] ?? 0);
                        $wbt[2] = (int) ($next[1][2][1] ?? 0);
                    }
                    break;
                case 'DecodeParms':
                    $this->processXrefDecodeParms($next, $state['columns']);
                    break;
            }

            $this->processXrefTypeFt($val[1], $sarr, $key, $xref, $filltrailer);
        }
    }

    /**
     * Process XREF type Prev
     *
     * @param RawObjectArray|null $next     Next token
     * @param int|null            $prevxref Previous XREF
     */
    protected function processXrefPrev(?array $next, ?int &$prevxref): void
    {
        if (\is_array($next) && $next[0] === 'numeric') {
            // get previous xref offset
            $prevxref = (int) $next[1];
        }
    }

    /**
     * Process XREF type DecodeParms
     *
     * @param RawObjectArray|null $next    Next token
     * @param int                 $columns Number of columns
     */
    protected function processXrefDecodeParms(?array $next, int &$columns): void
    {
        $decpar = $next[1] ?? null;
        if (!\is_array($decpar)) {
            return;
        }

        foreach ($decpar as $kdc => $vdc) {
            $nextDecpar = $decpar[$kdc + 1] ?? null;
            if (\is_array($nextDecpar) && $vdc[0] === '/' && $vdc[1] === 'Columns' && $nextDecpar[0] === 'numeric') {
                $columns = (int) $nextDecpar[1];
                break;
            }
        }

        $columns = \max(0, $columns);
    }

    /**
     * Process XREF type
     *
     * @param string                     $type        Type
     * @param array<int, RawObjectArray> $sarr        Stream data
     * @param int                        $key         Key
     * @param XrefData                   $xref        XREF data
     * @param bool                       $filltrailer Fill trailer
     */
    protected function processXrefTypeFt(string $type, array $sarr, int $key, array &$xref, bool $filltrailer): void
    {
        if (!$filltrailer) {
            return;
        }

        $next = $sarr[$key + 1] ?? null;

        if ($xref['trailer'] === []) {
            $xref['trailer'] = [
                'id' => [],
                'info' => '',
                'root' => '',
                'size' => 0,
            ];
        }

        switch ($type) {
            case 'Size':
                if (\is_array($next) && $next[0] === 'numeric') {
                    $xref['trailer']['size'] = (int) $next[1];
                }

                break;
            case 'ID':
                $id0 = $next[1][0][1] ?? null;
                $id1 = $next[1][1][1] ?? null;
                if (!\is_array($next) || !\is_string($id0) || !\is_string($id1) || $id0 === '' || $id1 === '') {
                    break;
                }
                $xref['trailer']['id'] = [
                    $id0,
                    $id1,
                ];
                break;
            default:
                $xref = $this->processXrefObjref($type, $sarr, $key, $xref);
                break;
        }
    }

    /**
     * Process XREF type Objref
     *
     * @param string                     $type Type
     * @param array<int, RawObjectArray> $sarr Stream data
     * @param int                        $key  Key
     * @param XrefData                   $xref XREF data
     *
     * @return XrefData XREF data.
     */
    protected function processXrefObjref(string $type, array $sarr, int $key, array $xref): array
    {
        $next = $sarr[$key + 1] ?? null;
        if (!\is_array($next) || $next[0] !== 'objref') {
            return $xref;
        }

        $val = $next[1];
        if (!\is_string($val)) {
            return $xref;
        }

        switch ($type) {
            case 'Root':
                $xref['trailer']['root'] = $val;
                break;
            case 'Info':
                $xref['trailer']['info'] = $val;
                break;
            case 'Encrypt':
                $xref['trailer']['encrypt'] = $val;
                break;
        }

        return $xref;
    }
}
