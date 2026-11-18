<?php

declare(strict_types=1);

namespace Ikram\Rag\Exceptions;

use RuntimeException;

/**
 * A vector's width differs from the one the search is running at. Comparing
 * vectors of different widths has no meaning, so this is raised rather than
 * padding the shorter one and returning confident nonsense.
 */
final class DimensionMismatch extends RuntimeException {}
