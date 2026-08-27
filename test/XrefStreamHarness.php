<?php

/**
 * XrefStreamHarness.php
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfParser
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * This file is part of tc-lib-pdf-parser software library.
 */

namespace Test;

use Com\Tecnick\Pdf\Parser\Process\XrefStream;

/**
 * XrefStream exposing its protected methods.
 *
 * @phpstan-import-type RawObjectArray from \Com\Tecnick\Pdf\Parser\Process\RawObject
 */
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
     *        'xref': array<string, int|string>,
     *    } $xref
     * @param array<int, array<int, int>> $sdata
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function processObjIndexesPublic(array &$xref, int &$obj_num, array $sdata): void
    {
        $this->processObjIndexes($xref, $obj_num, $sdata);
    }

    /**
     * @param array{
     *        'trailer': array{
     *            'encrypt'?: string,
     *            'id': array<int, string>,
     *            'info': string,
     *            'root': string,
     *            'size': int,
     *        },
     *        'xref': array<string, int|string>,
     *    } $xref
     * @param array<int, int>             $objNumbers
     * @param array<int, array<int, int>> $sdata
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function processObjIndexesMapPublic(array &$xref, array $objNumbers, array $sdata): void
    {
        $this->processObjIndexesMap($xref, $objNumbers, $sdata);
    }

    /**
     * @param array{
     *        'trailer': array{
     *            'encrypt'?: string,
     *            'id': array<int, string>,
     *            'info': string,
     *            'root': string,
     *            'size': int,
     *        },
     *        'xref': array<string, int|string>,
     *    } $xref
     * @param array<int, int> $sdatum
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function processSingleObjIndexPublic(array &$xref, int $objNum, array $sdatum): void
    {
        $this->processSingleObjIndex($xref, $objNum, $sdatum);
    }

    /**
     * @param RawObjectArray|null $indexObj
     *
     * @return array<int, array{0:int, 1:int}>|null
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function parseXrefIndexSectionsPublic(?array $indexObj): ?array
    {
        return $this->parseXrefIndexSections($indexObj);
    }

    /**
     * @param array<int, array{0:int, 1:int}> $indexSections
     *
     * @return array<int, int>
     */
    public function buildXrefObjectNumbersPublic(array $indexSections): array
    {
        return $this->buildXrefObjectNumbers($indexSections);
    }

    /** @param RawObjectArray|null $next */
    public function processXrefPrevPublic(?array $next, ?int &$prevxref): void
    {
        $this->processXrefPrev($next, $prevxref);
    }

    /**
     * @param RawObjectArray|null $next
     * @param array{
     *      index_sections: array<int, array{0:int, 1:int}>|null,
     *      prevxref: int|null,
     *      predictor: int,
     *      columns: int,
     *      colors: int,
     *      bits: int,
     *      size: int|null,
     *      valid_crs: bool
     * } $state
     */
    public function processXrefDecodeParmsPublic(?array $next, array &$state): void
    {
        $this->processXrefDecodeParms($next, $state);
    }

    /**
     * @param array<int, RawObjectArray> $sarr
     * @param array{
     *        trailer: array{encrypt?: string, id: array<int, string>, info: string, root: string, size: int},
     *        xref: array<string, int|string>,
     *    } $xref
     */
    public function processXrefTypeFtPublic(string $type, array $sarr, int $key, array &$xref, bool $filltrailer): void
    {
        $this->processXrefTypeFt($type, $sarr, $key, $xref, $filltrailer);
    }

    /**
     * @param array<int, RawObjectArray> $sarr
     * @param array{
     *        trailer: array{encrypt?: string, id: array<int, string>, info: string, root: string, size: int},
     *        xref: array<string, int|string>,
     *    } $xref
     *
     * @return array{
     *        trailer: array{encrypt?: string, id: array<int, string>, info: string, root: string, size: int},
     *        xref: array<string, int|string>,
     *    }
     */
    public function processXrefObjrefPublic(string $type, array $sarr, int $key, array $xref): array
    {
        return $this->processXrefObjref($type, $sarr, $key, $xref);
    }

    /**
     * @param array<int, RawObjectArray> $sarr
     * @param array{
     *      trailer: array{encrypt?: string, id: array<int, string>, info: string, root: string, size: int},
     *      xref: array<string, int|string>,
     * } $xref
     * @param array<int, int> $wbt
     * @param array{
     *      index_sections: array<int, array{0:int, 1:int}>|null,
     *      prevxref: int|null,
     *      predictor: int,
     *      columns: int,
     *      colors: int,
     *      bits: int,
     *      size: int|null,
     *      valid_crs: bool
     * } $state
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function processXrefTypePublic(
        array $sarr,
        array &$xref,
        array &$wbt,
        array &$state,
        bool $filltrailer,
    ): void {
        $this->processXrefType($sarr, $xref, $wbt, $state, $filltrailer);
    }
}
