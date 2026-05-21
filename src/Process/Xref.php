<?php

declare(strict_types=1);

/**
 * Xref.php
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
 * Com\Tecnick\Pdf\Parser\Process\Xref
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
 * @phpstan-import-type XrefData from \Com\Tecnick\Pdf\Parser\Process\XrefStream
 *
 * @phpstan-type XrefDataPartial array{
 *                 'trailer'?: array{
 *                     'encrypt'?: string,
 *                     'id': array<int, string>,
 *                     'info': string,
 *                     'root': string,
 *                     'size': int,
 *                 },
 *                 'xref': array<string, int>,
 *             }
 *
 * @phpstan-type XrefDataInput array{
 *                 'trailer'?: array{
 *                     'encrypt'?: string,
 *                     'id': array<int, string>,
 *                     'info': string,
 *                     'root': string,
 *                     'size': int,
 *                 },
 *                 'xref'?: array<string, int>,
 *             }
 */
abstract class Xref extends \Com\Tecnick\Pdf\Parser\Process\XrefStream
{
    /**
     * Default empty XREF data.
     *
     * @var XrefData
     */
    protected const XREF_EMPTY = [
        'trailer' => [
            'encrypt' => '',
            'id' => [],
            'info' => '',
            'root' => '',
            'size' => 0,
        ],
        'xref' => [],
    ];

    /**
     * XREF data.
     *
     * @var XrefData
     */
    protected array $xref = self::XREF_EMPTY;

    /**
     * Store the processed offsets
     *
     * @var array<int, int>
     */
    protected array $mrkoff = [];

    /**
     * Get content of indirect object.
     *
     * @param string $obj_ref  Object number and generation number separated by underscore character.
     * @param int    $offset   Object offset.
     * @param bool   $decoding If true decode streams.
     *
     * @return array<int, RawObjectArray> Object data.
     */
    abstract protected function getIndirectObject(string $obj_ref, int $offset = 0, bool $decoding = true): array;

    /**
     * Get Cross-Reference (xref) table and trailer data from PDF document data.
     *
     * @param int           $offset Xref offset (if know).
     * @param XrefDataInput $xref   Previous xref array (if any).
     *
     * @return XrefData Xref and trailer data.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    protected function getXrefData(int $offset = 0, array $xref = []): array
    {
        if (\in_array($offset, $this->mrkoff, true)) {
            throw new PPException('LOOP: this XRef offset has been already processed');
        }

        $this->mrkoff[] = $offset;
        $matches = [];
        if ($offset === 0) {
            // find last startxref
            $matchCount = \preg_match_all(
                '/[\r\n]startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF/i',
                $this->pdfdata,
                $matches,
                PREG_SET_ORDER,
                $offset,
            );
            if ($matchCount === false || $matchCount === 0) {
                throw new PPException('Unable to find startxref (1)');
            }

            $matches = \array_pop($matches);
            if ($matches === null) {
                throw new PPException('Unable to find startxref (2)');
            }

            $startxref = (int) ($matches[1] ?? 0);
        } elseif ((int) ($pos = \strpos($this->pdfdata, 'xref', $offset)) <= ($offset + 4)) {
            // Already pointing at the xref table
            $startxref = (int) $pos;
        } elseif (\preg_match('/([0-9]+[\s][0-9]+[\s]obj)/i', $this->pdfdata, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            // Cross-Reference Stream object
            $startxref = (int) $offset;
        } elseif (\preg_match(
            '/[\r\n]startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF/i',
            $this->pdfdata,
            $matches,
            PREG_OFFSET_CAPTURE,
            $offset,
        )) {
            // startxref found
            $startxref = (int) ($matches[1][0] ?? 0);
        } else {
            throw new PPException('Unable to find startxref (3)');
        }

        $xref['xref'] ??= [];

        // check xref position
        $xrefPos = \strpos($this->pdfdata, 'xref', $startxref);
        if ($xrefPos !== false && $xrefPos === $startxref) {
            // Cross-Reference
            $xref = $this->decodeXref($startxref, $xref);
        } else {
            // Cross-Reference Stream
            $xref = $this->decodeXrefStream($startxref, $xref);
        }

        if ($xref['xref'] === []) {
            throw new PPException('Unable to find xref (4)');
        }

        return $xref;
    }

    /**
     * Decode the Cross-Reference section
     *
     * @param int              $startxref Offset at which the xref section starts (position of the 'xref' keyword).
     * @param XrefDataPartial  $xref      Previous xref array (if any).
     *
     * @return XrefData Xref and trailer data.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    protected function decodeXref(int $startxref, array $xref): array
    {
        $startxref += 4; // 4 is the length of the word 'xref'
        // skip initial white space chars:
        // \x00 null (NUL)
        // \x09 horizontal tab (HT)
        // \x0A line feed (LF)
        // \x0C form feed (FF)
        // \x0D carriage return (CR)
        // \x20 space (SP)
        $offset = $startxref + \strspn($this->pdfdata, "\x00\x09\x0a\x0c\x0d\x20", $startxref);
        // initialize object number
        $obj_num = 0;
        $matches = [];
        // search for cross-reference entries or subsection
        while (
            \preg_match(
                '/(\d+)[\x20](\d+)[\x20]?([nf]?)(\r\n|[\x20]?[\r\n])/',
                $this->pdfdata,
                $matches,
                PREG_OFFSET_CAPTURE,
                $offset,
            ) === 1
        ) {
            $matchOffset = (int) ($matches[0][1] ?? -1);
            if ($matchOffset !== $offset) {
                // we are on another section
                break;
            }

            $offset += \strlen($matches[0][0] ?? '');
            $flag = $matches[3][0] ?? '';
            if ($flag === 'n') {
                // create unique object index: [object number]_[generation number]
                $index = $obj_num . '_' . (int) ($matches[2][0] ?? 0);
                // check if object already exist
                if (!\array_key_exists($index, $xref['xref'])) {
                    // store object offset position
                    $xref['xref'][$index] = (int) ($matches[1][0] ?? 0);
                }

                ++$obj_num;
                continue;
            }

            if ($flag === 'f') {
                ++$obj_num;
                continue;
            }

            // object number (index) - handle any other character (free object)
            $obj_num = (int) ($matches[1][0] ?? 0);
        }

        // get trailer data
        $trmatches_temp = [];
        $trl = \preg_match('/trailer[\s]*+<<(.*)>>/isU', $this->pdfdata, $trmatches_temp, PREG_OFFSET_CAPTURE, $offset);
        if ($trl !== 1) {
            throw new PPException('Unable to find trailer');
        }

        if (($trmatches_temp[1][0] ?? null) === null) {
            throw new PPException('Unable to parse trailer data');
        }

        return $this->getTrailerData($xref, $trmatches_temp[1][0]);
    }

    /**
     * Decode the Cross-Reference section
     *
     * @param XrefDataPartial $xref         Previous xref array (if any).
     * @param string          $trailer_data Trailer content string.
     *
     * @return XrefData Xref and trailer data.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    protected function getTrailerData(array $xref, string $trailer_data): array
    {
        $matches = [];
        if (($xref['trailer'] ?? []) === []) {
            // get only the last updated version
            $xref['trailer'] = [
                'id' => [],
                'info' => '',
                'root' => '',
                'size' => 0,
            ];

            // parse trailer_data
            if (\preg_match('/Size[\s]+([0-9]+)/i', $trailer_data, $matches) === 1) {
                $xref['trailer']['size'] = (int) ($matches[1] ?? 0);
            }

            if (\preg_match('/Root[\s]+([0-9]+)[\s]+([0-9]+)[\s]+R/i', $trailer_data, $matches) === 1) {
                $xref['trailer']['root'] = (int) ($matches[1] ?? 0) . '_' . (int) ($matches[2] ?? 0);
            }

            if (\preg_match('/Encrypt[\s]+([0-9]+)[\s]+([0-9]+)[\s]+R/i', $trailer_data, $matches) === 1) {
                $xref['trailer']['encrypt'] = (int) ($matches[1] ?? 0) . '_' . (int) ($matches[2] ?? 0);
            }

            if (\preg_match('/Info[\s]+([0-9]+)[\s]+([0-9]+)[\s]+R/i', $trailer_data, $matches) === 1) {
                $xref['trailer']['info'] = (int) ($matches[1] ?? 0) . '_' . (int) ($matches[2] ?? 0);
            }

            if (\preg_match('/ID[\s]*+[\[][\s]*+[<]([^>]*+)[>][\s]*+[<]([^>]*+)[>]/i', $trailer_data, $matches) === 1) {
                /** @var array<int, string> $id_array */
                $id_array = [$matches[1] ?? '', $matches[2] ?? ''];
                $xref['trailer']['id'] = $id_array;
            }
        }

        if (\preg_match('/Prev[\s]+([0-9]+)/i', $trailer_data, $matches) === 1) {
            // get previous xref
            return $this->getXrefData((int) ($matches[1] ?? 0), $xref);
        }

        $xref['trailer'] ??= self::XREF_EMPTY['trailer'];

        return $xref;
    }

    /**
     * Decode the Cross-Reference Stream section
     *
     * @param int             $startxref Offset at which the xref section starts.
     * @param XrefDataPartial $xref      Previous xref array (if any).
     *
     * @return XrefData Xref and trailer data.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    protected function decodeXrefStream(int $startxref, array $xref): array
    {
        // try to read Cross-Reference Stream
        $xrefobj = $this->getRawObject($startxref);
        if (!\is_string($xrefobj[1])) {
            throw new PPException('Unable to find xref stream');
        }

        $xrefcrs = $this->getIndirectObject($xrefobj[1], $startxref, true);

        $filltrailer = ($xref['trailer'] ?? []) === [];
        if ($filltrailer) {
            $xref['trailer'] = self::XREF_EMPTY['trailer'];
        }

        $valid_crs = false;
        $columns = 0;
        $sarr = $xrefcrs[0][1] ?? [];
        /** @var array<int, array>|string $sarr */
        if (!\is_array($sarr)) {
            $sarr = [];
        }

        /** @var array<int, RawObjectArray> $sarr */
        /** @var XrefData $xref */

        $wbt = [0, 0, 0];
        $state = [
            'index_first' => null,
            'prevxref' => null,
            'columns' => $columns,
            'valid_crs' => $valid_crs,
        ];
        $this->processXrefType($sarr, $xref, $wbt, $state, $filltrailer);
        $index_first = $state['index_first'];
        $columns = $state['columns'];
        $valid_crs = $state['valid_crs'];
        // decode data
        $streamData = $xrefcrs[1][3][0] ?? null;
        if ($valid_crs && \is_string($streamData)) {
            // number of bytes in a row
            $rowlen = (int) ($columns + 1);
            // convert the stream into an array of integers
            $sdata = \unpack('C*', $streamData);
            if ($sdata === false) {
                throw new PPException('Unable to unpack xref stream data');
            }

            // split the rows
            $sdata = \array_chunk($sdata, \max(1, $rowlen), false);
            // initialize decoded array
            $ddata = [];
            // initialize first row with zeros
            $filllen = \max(0, $rowlen);
            $prev_row = \array_fill(0, $filllen, 0);
            $this->pngUnpredictor($sdata, $ddata, $columns, $prev_row); //@phpstan-ignore argument.type
            // complete decoding
            $sdata = [];
            $this->processDdata($sdata, $ddata, $wbt);
            $ddata = [];
            // fill xref
            $obj_num = $index_first ?? 0;

            $this->processObjIndexes($xref, $obj_num, $sdata);
        }

        // end decoding data
        $prevxref = $state['prevxref'];
        if ($prevxref === null) {
            return $xref;
        }

        // get previous xref
        return $this->getXrefData($prevxref, $xref);
    }

    /**
     * Process ddata
     *
     * @param array<int, array<int, int>> $sdata
     * @param array<int, array<int, int>> $ddata
     * @param array<int, int>             $wbt
     */
    protected function processDdata(array &$sdata, array $ddata, array $wbt): void
    {
        // for every row
        foreach ($ddata as $key => $row) {
            // initialize new row
            $sdata[$key] = [0, 0, 0];
            if (($wbt[0] ?? 0) === 0) {
                // default type field
                $sdata[$key][0] = 1;
            }

            $idx = 0; // count bytes in the row
            // for every column
            for ($col = 0; $col < 3; ++$col) {
                // for every byte on the column
                $colWidth = (int) ($wbt[$col] ?? 0);
                for ($byte = 0; $byte < $colWidth; ++$byte) {
                    if (\array_key_exists($idx, $row)) {
                        $rowValue = $row[$idx] ?? null;
                        if (!\is_int($rowValue)) {
                            ++$idx;
                            continue;
                        }

                        $currentValue = (int) ($sdata[$key][$col] ?? 0);
                        $shift = ($colWidth - 1 - $byte) * 8;
                        $sdata[$key][$col] = $currentValue + ($rowValue << $shift);
                    }

                    ++$idx;
                }
            }
        }
    }
}
