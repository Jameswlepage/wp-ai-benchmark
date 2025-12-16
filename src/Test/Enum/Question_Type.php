<?php
/**
 * Question type enumeration.
 *
 * @package WordPress\AI_Benchmark\Test\Enum
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\Test\Enum;

/**
 * Defines the types of questions for knowledge tests.
 */
enum Question_Type: string {
	case MULTIPLE_CHOICE = 'multiple_choice';
	case SHORT_ANSWER    = 'short_answer';
}
