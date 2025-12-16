<?php
/**
 * Test type enumeration.
 *
 * @package WordPress\AI_Benchmark\Test\Enum
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\Test\Enum;

/**
 * Defines the types of tests supported by the benchmark.
 */
enum Test_Type: string {
	case KNOWLEDGE = 'knowledge';
	case EXECUTION = 'execution';
}
