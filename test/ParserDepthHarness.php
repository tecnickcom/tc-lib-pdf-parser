<?php

/**
 * ParserDepthHarness.php
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

use Com\Tecnick\Pdf\Parser\Parser;

/**
 * Parser with a lowered resolution depth limit that records how deep it actually nested.
 *
 * @phpstan-import-type RawObjectArray from \Com\Tecnick\Pdf\Parser\Process\RawObject
 */
class ParserDepthHarness extends Parser
{
    /**
     * Lowered limit, so the guard can be exercised with a small document.
     */
    protected const MAX_RESOLUTION_DEPTH = 8;

    /**
     * Deepest nesting of getIndirectObject() observed so far.
     */
    private int $maxDepth = 0;

    /**
     * Current nesting of getIndirectObject().
     */
    private int $depth = 0;

    /**
     * Deepest nesting of getIndirectObject() observed so far.
     */
    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    /**
     * The configured depth limit.
     */
    public function getDepthLimit(): int
    {
        return static::MAX_RESOLUTION_DEPTH;
    }

    /**
     * @param string $obj_ref  Object number and generation number separated by underscore character.
     * @param int    $offset   Object offset.
     * @param bool   $decoding If true decode streams.
     *
     * @return array<int, RawObjectArray> Object data.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    protected function getIndirectObject(string $obj_ref, int $offset = 0, bool $decoding = true): array
    {
        ++$this->depth;
        $this->maxDepth = \max($this->maxDepth, $this->depth);

        try {
            return parent::getIndirectObject($obj_ref, $offset, $decoding);
        } finally {
            --$this->depth;
        }
    }
}
