<?php
/**
 * Xref.php
 *
 * @since       2011-05-23
 * @category    Library
 * @package     PdfParser
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2016 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * This file is part of tc-lib-pdf-parser software library.
 */

namespace Com\Tecnick\Pdf\Parser\Process;

use \Com\Tecnick\Pdf\Parser\Exception as PPException;

/**
 * Com\Tecnick\Pdf\Parser\Process\Xref
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
abstract class Xref extends \Com\Tecnick\Pdf\Parser\Process\XrefStream
{
    /**
     * XREF data.
     *
     * @var array
     */
    protected $xref = array();

    /**
     * Store the processed offsets
     *
     * @var array
     */
    protected $mrkoff = array();

    /**
     * Get Cross-Reference (xref) table and trailer data from PDF document data.
     *
     * @param int   $offset Xref offset (if know).
     * @param array $xref   Previous xref array (if any).
     *
     * @return array Xref and trailer data.
     */
    protected function getXrefData($offset = 0, $xref = array())
    {
        if (in_array($offset, $this->mrkoff)) {
            throw new PPException('LOOP: this XRef offset has been already processed');
        }
        $this->mrkoff[] = $offset;
        if ($offset == 0) {
            // find last startxref
            if (preg_match_all(
                '/[\r\n]startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF/i',
                $this->pdfdata,
                $matches,
                PREG_SET_ORDER,
                $offset
            ) == 0) {
                throw new PPException('Unable to find startxref');
            }
            $matches = array_pop($matches);
            $startxref = $matches[1];
        } elseif (($pos = strpos($this->pdfdata, 'xref', $offset)) <= ($offset + 4)) {
            // Already pointing at the xref table
            $startxref = $pos;
        } elseif (preg_match('/([0-9]+[\s][0-9]+[\s]obj)/i', $this->pdfdata, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            // Cross-Reference Stream object
            $startxref = $offset;
        } elseif (preg_match(
            '/[\r\n]startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF/i',
            $this->pdfdata,
            $matches,
            PREG_OFFSET_CAPTURE,
            $offset
        )) {
            // startxref found
            $startxref = $matches[1][0];
        } else {
            throw new PPException('Unable to find startxref');
        }
        // check xref position
        if (strpos($this->pdfdata, 'xref', $startxref) == $startxref) {
            // Cross-Reference
            $xref = $this->decodeXref($startxref, $xref);
        } else {
            // Cross-Reference Stream
            $xref = $this->decodeXrefStream($startxref, $xref);
        }
        if (empty($xref)) {
            throw new PPException('Unable to find xref');
        }
        return $xref;
    }

    /**
     * Decode the Cross-Reference section
     *
     * @param int   $startxref Offset at which the xref section starts (position of the 'xref' keyword).
     * @param array $xref      Previous xref array (if any).
     *
     * @return array Xref and trailer data.
     */
    protected function decodeXref($startxref, $xref = array())
    {
        $startxref += 4; // 4 is the length of the word 'xref'
        // skip initial white space chars:
        // \x00 null (NUL)
        // \x09 horizontal tab (HT)
        // \x0A line feed (LF)
        // \x0C form feed (FF)
        // \x0D carriage return (CR)
        // \x20 space (SP)
        $offset = $startxref + strspn($this->pdfdata, "\x00\x09\x0a\x0c\x0d\x20", $startxref);
        // initialize object number
        $obj_num = 0;
        // search for cross-reference entries or subsection
        while (preg_match(
            '/([0-9]+)[\x20]([0-9]+)[\x20]?([nf]?)(\r\n|[\x20]?[\r\n])/',
            $this->pdfdata,
            $matches,
            PREG_OFFSET_CAPTURE,
            $offset
        ) > 0) {
            if ($matches[0][1] != $offset) {
                // we are on another section
                break;
            }
            $offset += strlen($matches[0][0]);
            if ($matches[3][0] == 'n') {
                // create unique object index: [object number]_[generation number]
                $index = $obj_num.'_'.intval($matches[2][0]);
                // check if object already exist
                if (!isset($xref['xref'][$index])) {
                    // store object offset position
                    $xref['xref'][$index] = intval($matches[1][0]);
                }
                ++$obj_num;
            } elseif ($matches[3][0] == 'f') {
                ++$obj_num;
            } else {
                // object number (index)
                $obj_num = intval($matches[1][0]);
            }
        }
        // get trailer data
        if (!preg_match('/trailer[\s]*<<(.*)>>/isU', $this->pdfdata, $matches, PREG_OFFSET_CAPTURE, $offset) > 0) {
            throw new PPException('Unable to find trailer');
        }
        return $this->getTrailerData($xref, $matches);
    }

    /**
     * Decode the Cross-Reference section
     *
     * @param array $xref    Previous xref array (if any).
     * @param array $matches Matches containing traile sections
     *
     * @return array Xref and trailer data.
     */
    protected function getTrailerData($xref, $matches)
    {
        $trailer_data = $matches[1][0];
        if (!isset($xref['trailer']) || empty($xref['trailer'])) {
            // get only the last updated version
            $xref['trailer'] = array();
            // parse trailer_data
            if (preg_match('/Size[\s]+([0-9]+)/i', $trailer_data, $matches) > 0) {
                $xref['trailer']['size'] = intval($matches[1]);
            }
            if (preg_match('/Root[\s]+([0-9]+)[\s]+([0-9]+)[\s]+R/i', $trailer_data, $matches) > 0) {
                $xref['trailer']['root'] = intval($matches[1]).'_'.intval($matches[2]);
            }
            if (preg_match('/Encrypt[\s]+([0-9]+)[\s]+([0-9]+)[\s]+R/i', $trailer_data, $matches) > 0) {
                $xref['trailer']['encrypt'] = intval($matches[1]).'_'.intval($matches[2]);
            }
            if (preg_match('/Info[\s]+([0-9]+)[\s]+([0-9]+)[\s]+R/i', $trailer_data, $matches) > 0) {
                $xref['trailer']['info'] = intval($matches[1]).'_'.intval($matches[2]);
            }
            if (preg_match('/ID[\s]*[\[][\s]*[<]([^>]*)[>][\s]*[<]([^>]*)[>]/i', $trailer_data, $matches) > 0) {
                $xref['trailer']['id'] = array();
                $xref['trailer']['id'][0] = $matches[1];
                $xref['trailer']['id'][1] = $matches[2];
            }
        }
        if (preg_match('/Prev[\s]+([0-9]+)/i', $trailer_data, $matches) > 0) {
            // get previous xref
            $xref = $this->getXrefData(intval($matches[1]), $xref);
        }
        return $xref;
    }

    /**
     * Decode the Cross-Reference Stream section
     *
     * @param int   $startxref Offset at which the xref section starts.
     * @param array $xref      Previous xref array (if any).
     *
     * @return array Xref and trailer data.
     */
    protected function decodeXrefStream($startxref, $xref = array())
    {
        // try to read Cross-Reference Stream
        $xrefobj = $this->getRawObject($startxref);
        $xrefcrs = $this->getIndirectObject($xrefobj[1], $startxref, true);
        if (!isset($xref['trailer']) || empty($xref['trailer'])) {
            // get only the last updated version
            $xref['trailer'] = array();
            $filltrailer = true;
        } else {
            $filltrailer = false;
        }
        if (!isset($xref['xref'])) {
            $xref['xref'] = array();
        }
        $valid_crs = false;
        $columns = 0;
        $sarr = $xrefcrs[0][1];
        if (!is_array($sarr)) {
            $sarr = array();
        }
        $wbt = array();
        $index_first = null;
        $prevxref = null;
        $this->processXrefType($sarr, $xref, $wbt, $index_first, $prevxref, $columns, $valid_crs, $filltrailer);
        // decode data
        if ($valid_crs && isset($xrefcrs[1][3][0])) {
            // number of bytes in a row
            $rowlen = ($columns + 1);
            // convert the stream into an array of integers
            $sdata = unpack('C*', $xrefcrs[1][3][0]);
            // split the rows
            $sdata = array_chunk($sdata, $rowlen);
            // initialize decoded array
            $ddata = array();
            // initialize first row with zeros
            $prev_row = array_fill(0, $rowlen, 0);
            $this->pngUnpredictor($sdata, $ddata, $columns, $prev_row);
            // complete decoding
            $sdata = array();
            $this->processDdata($sdata, $ddata, $wbt);
            $ddata = array();
            // fill xref
            if ($index_first !== null) {
                $obj_num = $index_first;
            } else {
                $obj_num = 0;
            }
            $this->processObjIndexes($xref, $obj_num, $sdata);
        } // end decoding data
        if ($prevxref != null) {
            // get previous xref
            $xref = $this->getXrefData($prevxref, $xref);
        }
        return $xref;
    }

    /**
     * Process ddata
     *
     * @param array $sdata
     * @param array $ddata
     * @param array $wbt
     */
    protected function processDdata(&$sdata, $ddata, $wbt)
    {
        // for every row
        foreach ($ddata as $key => $row) {
            // initialize new row
            $sdata[$key] = array(0, 0, 0);
            if ($wbt[0] == 0) {
                // default type field
                $sdata[$key][0] = 1;
            }
            $idx = 0; // count bytes in the row
            // for every column
            for ($col = 0; $col < 3; ++$col) {
                // for every byte on the column
                for ($byte = 0; $byte < $wbt[$col]; ++$byte) {
                    if (isset($row[$idx])) {
                        $sdata[$key][$col] += ($row[$idx] << (($wbt[$col] - 1 - $byte) * 8));
                    }
                    ++$idx;
                }
            }
        }
    }
}
