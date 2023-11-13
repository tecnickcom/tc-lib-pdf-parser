<?php

/**
 * XrefStream.php
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfParser
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2023 Nicola Asuni - Tecnick.com LTD
 * @license   http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
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
 * @copyright 2011-2023 Nicola Asuni - Tecnick.com LTD
 * @license   http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-parser
 */
abstract class XrefStream extends \Com\Tecnick\Pdf\Parser\Process\RawObject
{
    /**
     * Process object indexes
     *
     * @param array{
     *        'trailer': array{
     *            'encrypt'?: string,
     *            'id': array<int, string>,
     *            'info': string,
     *            'root': string,
     *            'size': int,
     *        },
     *        'xref': array<string, int>,
     *    } $xref    XREF data
     * @param int                            $obj_num Object number
     * @param array<int, array<int, string>> $sdata   Stream data
     */
    protected function processObjIndexes(array &$xref, int &$obj_num, array $sdata): void
    {
        foreach ($sdata as $sdatum) {
            switch ($sdatum[0]) {
                case 0:
                    // (f) linked list of free objects
                    break;
                case 1:
                    // (n) objects that are in use but are not compressed
                    // create unique object index: [object number]_[generation number]
                    $index = $obj_num . '_' . $sdatum[2];
                    // check if object already exist
                    if (! isset($xref['xref'][$index])) {
                        // store object offset position
                        $xref['xref'][$index] = $sdatum[1];
                    }

                    break;
                case 2:
                    // compressed objects
                    // $row[1] = object number of the object stream in which this object is stored
                    // $row[2] = index of this object within the object stream
                    $index = $sdatum[1] . '_0_' . $sdatum[2];
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
     */
    protected function pngUnpredictor(array $sdata, array &$ddata, int $columns, array $prev_row): void
    {
        // for each row apply PNG unpredictor
        foreach ($sdata as $key => $row) {
            // initialize new row
            $ddata[$key] = [];
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
                        $this->minDistance($ddata, $key, $row, $idx, $jdx, $row_left, $row_up, $row_upleft);
                        break;
                    default:
                        // PNG prediction (on encoding, PNG optimum)
                        throw new PPException('Unknown PNG predictor');
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
     * @param array<int, int>             $row        Row
     * @param int                         $idx        Index
     * @param int                         $jdx        Jdx
     * @param int                         $row_left   Row left
     * @param int                         $row_up     Row up
     * @param int                         $row_upleft Row upleft
     */
    protected function minDistance(
        array &$ddata,
        int $key,
        array $row,
        int $idx,
        int $jdx,
        int $row_left,
        int $row_up,
        int $row_upleft,
    ): void {
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
     * @param array<int, array{
     *                 0: string,
     *                 1: string|array<int, array{
     *                     0: string,
     *                     1: string|array<int, array{
     *                         0: string,
     *                         1: string|array<int, array{
     *                             0: string,
     *                             1: string|array<int, array{
     *                                 0: string,
     *                                 1: string|array<int, array{
     *                                     0: string,
     *                                     1: string,
     *                                     2: int,
     *                                     3?: array{string, array<string>},
     *                                 }>,
     *                                 2: int,
     *                                 3?: array{string, array<string>},
     *                             }>,
     *                             2: int,
     *                             3?: array{string, array<string>},
     *                         }>,
     *                         2: int,
     *                         3?: array{string, array<string>},
     *                     }>,
     *                     2: int,
     *                     3?: array{string, array<string>},
     *                   }>,
     *                 2: int,
     *                 3?: array{string, array<string>},
     *             }> $sarr        Stream data
     * @param array{
     *        'trailer': array{
     *            'encrypt'?: string,
     *            'id': array<int, string>,
     *            'info': string,
     *            'root': string,
     *            'size': int,
     *        },
     *        'xref': array<string, int>,
     *    } $xref        XREF data
     * @param array<int, int>   $wbt         WBT data
     * @param int               $index_first Index first
     * @param int               $prevxref    Previous XREF
     * @param int               $columns     Number of columns
     * @param int               $valid_crs   Valid CRS
     * @param bool              $filltrailer Fill trailer
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function processXrefType(
        array $sarr,
        array &$xref,
        array &$wbt,
        int &$index_first,
        int &$prevxref,
        int &$columns,
        int &$valid_crs,
        bool $filltrailer
    ): void {
        foreach ($sarr as $key => $val) {
            if ($val[0] !== '/') {
                continue;
            }

            if (! is_string($val[1])) {
                continue;
            }

            switch ($val[1]) {
                case 'Type':
                    $valid_crs = (($sarr[($key + 1)][0] == '/') && ($sarr[($key + 1)][1] == 'XRef'));
                    break;
                case 'Index':
                    // first object number in the subsection
                    $index_first = (int) $sarr[($key + 1)][1][0][1];
                    // number of entries in the subsection
                    // $index_entries = intval($sarr[($key + 1)][1][1][1]);
                    break;
                case 'Prev':
                    $this->processXrefPrev($sarr, $key, $prevxref);
                    break;
                case 'W':
                    // number of bytes (in the decoded stream) of the corresponding field
                    $wbt[0] = (int) $sarr[($key + 1)][1][0][1];
                    $wbt[1] = (int) $sarr[($key + 1)][1][1][1];
                    $wbt[2] = (int) $sarr[($key + 1)][1][2][1];
                    break;
                case 'DecodeParms':
                    $this->processXrefDecodeParms($sarr, $key, $columns);
                    break;
            }

            $this->processXrefTypeFt($val[1], $sarr, $key, $xref, $filltrailer);
        }
    }

    /**
     * Process XREF type Prev
     *
     * @param array<int, array{
     *                 0: string,
     *                 1: string|array<int, array{
     *                     0: string,
     *                     1: string|array<int, array{
     *                         0: string,
     *                         1: string|array<int, array{
     *                             0: string,
     *                             1: string|array<int, array{
     *                                 0: string,
     *                                 1: string|array<int, array{
     *                                     0: string,
     *                                     1: string,
     *                                     2: int,
     *                                     3?: array{string, array<string>},
     *                                 }>,
     *                                 2: int,
     *                                 3?: array{string, array<string>},
     *                             }>,
     *                             2: int,
     *                             3?: array{string, array<string>},
     *                         }>,
     *                         2: int,
     *                         3?: array{string, array<string>},
     *                     }>,
     *                     2: int,
     *                     3?: array{string, array<string>},
     *                   }>,
     *                 2: int,
     *                 3?: array{string, array<string>},
     *             }> $sarr     Stream data
     * @param int               $key      Key
     * @param int               $prevxref Previous XREF
     */
    protected function processXrefPrev(array $sarr, int $key, int &$prevxref): void
    {
        if ($sarr[($key + 1)][0] == 'numeric') {
            // get previous xref offset
            $prevxref = (int) $sarr[($key + 1)][1];
        }
    }

    /**
     * Process XREF type DecodeParms
     *
     * @param array<int, array{
     *                 0: string,
     *                 1: string|array<int, array{
     *                     0: string,
     *                     1: string|array<int, array{
     *                         0: string,
     *                         1: string|array<int, array{
     *                             0: string,
     *                             1: string|array<int, array{
     *                                 0: string,
     *                                 1: string|array<int, array{
     *                                     0: string,
     *                                     1: string,
     *                                     2: int,
     *                                     3?: array{string, array<string>},
     *                                 }>,
     *                                 2: int,
     *                                 3?: array{string, array<string>},
     *                             }>,
     *                             2: int,
     *                             3?: array{string, array<string>},
     *                         }>,
     *                         2: int,
     *                         3?: array{string, array<string>},
     *                     }>,
     *                     2: int,
     *                     3?: array{string, array<string>},
     *                   }>,
     *                 2: int,
     *                 3?: array{string, array<string>},
     *             }> $sarr Stream data
     * @param int               $key     Key
     * @param int               $columns Number of columns
     */
    protected function processXrefDecodeParms(array $sarr, int $key, int &$columns): void
    {
        $decpar = $sarr[($key + 1)][1];
        if (! is_array($decpar)) {
            return;
        }

        foreach ($decpar as $kdc => $vdc) {
            if (($vdc[0] == '/') && ($vdc[1] == 'Columns') && ($decpar[($kdc + 1)][0] == 'numeric')) {
                $columns = (int) $decpar[($kdc + 1)][1];
                break;
            }
        }

        $columns = max(0, $columns);
    }

    /**
     * Process XREF type
     *
     * @param string            $type        Type
     * @param array<int, array{
     *                 0: string,
     *                 1: string|array<int, array{
     *                     0: string,
     *                     1: string|array<int, array{
     *                         0: string,
     *                         1: string|array<int, array{
     *                             0: string,
     *                             1: string|array<int, array{
     *                                 0: string,
     *                                 1: string|array<int, array{
     *                                     0: string,
     *                                     1: string,
     *                                     2: int,
     *                                     3?: array{string, array<string>},
     *                                 }>,
     *                                 2: int,
     *                                 3?: array{string, array<string>},
     *                             }>,
     *                             2: int,
     *                             3?: array{string, array<string>},
     *                         }>,
     *                         2: int,
     *                         3?: array{string, array<string>},
     *                     }>,
     *                     2: int,
     *                     3?: array{string, array<string>},
     *                   }>,
     *                 2: int,
     *                 3?: array{string, array<string>},
     *             }> $sarr  Stream data
     * @param int               $key         Key
     * @param array{
     *        'trailer': array{
     *            'encrypt'?: string,
     *            'id': array<int, string>,
     *            'info': string,
     *            'root': string,
     *            'size': int,
     *        },
     *        'xref': array<string, int>,
     *    } $xref        XREF data
     * @param bool              $filltrailer Fill trailer
     */
    protected function processXrefTypeFt(string $type, array $sarr, int $key, array &$xref, bool $filltrailer): void
    {
        if (! $filltrailer) {
            return;
        }

        switch ($type) {
            case 'Size':
                if ($sarr[($key + 1)][0] == 'numeric') {
                    $xref['trailer']['size'] = $sarr[($key + 1)][1];
                }

                break;
            case 'ID':
                $xref['trailer']['id'] = [];
                $xref['trailer']['id'][0] = $sarr[($key + 1)][1][0][1];
                $xref['trailer']['id'][1] = $sarr[($key + 1)][1][1][1];
                break;
            default:
                $this->processXrefObjref($type, $sarr, $key, $xref);
                break;
        }
    }

    /**
     * Process XREF type Objref
     *
     * @param string            $type Type
     * @param array<int, array{
     *                 0: string,
     *                 1: string|array<int, array{
     *                     0: string,
     *                     1: string|array<int, array{
     *                         0: string,
     *                         1: string|array<int, array{
     *                             0: string,
     *                             1: string|array<int, array{
     *                                 0: string,
     *                                 1: string|array<int, array{
     *                                     0: string,
     *                                     1: string,
     *                                     2: int,
     *                                     3?: array{string, array<string>},
     *                                 }>,
     *                                 2: int,
     *                                 3?: array{string, array<string>},
     *                             }>,
     *                             2: int,
     *                             3?: array{string, array<string>},
     *                         }>,
     *                         2: int,
     *                         3?: array{string, array<string>},
     *                     }>,
     *                     2: int,
     *                     3?: array{string, array<string>},
     *                   }>,
     *                 2: int,
     *                 3?: array{string, array<string>},
     *             }> $sarr Stream data
     * @param int               $key  Key
     * @param array{
     *        'trailer': array{
     *            'encrypt'?: string,
     *            'id': array<int, string>,
     *            'info': string,
     *            'root': string,
     *            'size': int,
     *        },
     *        'xref': array<string, int>,
     *    } $xref XREF data
     */
    protected function processXrefObjref(string $type, array $sarr, int $key, array &$xref): void
    {
        if (! isset($sarr[($key + 1)]) || ($sarr[($key + 1)][0] !== 'objref')) {
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
