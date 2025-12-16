<?php
/**
 * Answer matching type enumeration.
 *
 * @package WordPress\AI_Benchmark\Test\Enum
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\Test\Enum;

/**
 * Defines how answers should be matched for knowledge tests.
 */
enum Answer_Type: string {
	case EXACT    = 'exact';
	case REGEX    = 'regex';
	case CONTAINS = 'contains';
}
