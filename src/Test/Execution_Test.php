<?php
/**
 * Execution test value object.
 *
 * @package WordPress\AI_Benchmark\Test
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\Test;

use WordPress\AI_Benchmark\Test\Enum\Test_Type;

/**
 * Value object representing an execution test (HumanEval-style).
 *
 * Supports code generation tasks with three-layer evaluation:
 * - Static checks (pattern matching)
 * - Runtime checks (code execution and assertions)
 * - AI judge (quality evaluation)
 */
final class Execution_Test implements Test_Interface {

	/**
	 * Test identifier.
	 *
	 * @var string
	 */
	private readonly string $id;

	/**
	 * Test category.
	 *
	 * @var string
	 */
	private readonly string $category;

	/**
	 * Difficulty level.
	 *
	 * @var string|null
	 */
	private readonly ?string $difficulty;

	/**
	 * Task prompt.
	 *
	 * @var string
	 */
	private readonly string $prompt;

	/**
	 * Expected behavior description.
	 *
	 * @var string
	 */
	private readonly string $expected_behavior;

	/**
	 * List of requirements.
	 *
	 * @var list<string>
	 */
	private readonly array $requirements;

	/**
	 * Context information.
	 *
	 * @var array<string, mixed>|null
	 */
	private readonly ?array $context;

	/**
	 * Static check configuration.
	 *
	 * @var array<string, mixed>|null
	 */
	private readonly ?array $static_checks;

	/**
	 * Runtime check configuration.
	 *
	 * @var array<string, mixed>|null
	 */
	private readonly ?array $runtime_checks;

	/**
	 * Judge evaluation configuration.
	 *
	 * @var array<string, mixed>|null
	 */
	private readonly ?array $judge_config;

	/**
	 * Reference solution code.
	 *
	 * @var string|null
	 */
	private readonly ?string $reference_solution;

	/**
	 * Constructor.
	 *
	 * @param string                    $id                 Test identifier.
	 * @param string                    $category           Test category.
	 * @param string                    $prompt             Task prompt.
	 * @param string                    $expected_behavior  Expected behavior description.
	 * @param list<string>              $requirements       List of requirements.
	 * @param array<string, mixed>|null $context            Context information.
	 * @param array<string, mixed>|null $static_checks      Static check configuration.
	 * @param array<string, mixed>|null $runtime_checks     Runtime check configuration.
	 * @param array<string, mixed>|null $judge_config       Judge configuration.
	 * @param string|null               $reference_solution Reference solution.
	 * @param string|null               $difficulty         Difficulty level.
	 */
	public function __construct(
		string $id,
		string $category,
		string $prompt,
		string $expected_behavior,
		array $requirements = [],
		?array $context = null,
		?array $static_checks = null,
		?array $runtime_checks = null,
		?array $judge_config = null,
		?string $reference_solution = null,
		?string $difficulty = null,
	) {
		$this->id                 = $id;
		$this->category           = $category;
		$this->prompt             = $prompt;
		$this->expected_behavior  = $expected_behavior;
		$this->requirements       = $requirements;
		$this->context            = $context;
		$this->static_checks      = $static_checks;
		$this->runtime_checks     = $runtime_checks;
		$this->judge_config       = $judge_config;
		$this->reference_solution = $reference_solution;
		$this->difficulty         = $difficulty;
	}

	/**
	 * Create an Execution_Test from an array.
	 *
	 * @param array<string, mixed> $data Test data from JSON.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException If required fields are missing.
	 */
	public static function from_array( array $data ): self {
		$required = [ 'id', 'category', 'prompt', 'expected_behavior' ];
		foreach ( $required as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Execution test missing required field: %s', esc_html( $field ) )
				);
			}
		}

		/** @var list<string> $requirements */
		$requirements = [];
		if ( isset( $data['requirements'] ) && is_array( $data['requirements'] ) ) {
			foreach ( $data['requirements'] as $req ) {
				if ( is_string( $req ) ) {
					$requirements[] = $req;
				}
			}
		}

		/** @var array<string, mixed>|null $context */
		$context = isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : null;

		/** @var array<string, mixed>|null $static_checks */
		$static_checks = isset( $data['static_checks'] ) && is_array( $data['static_checks'] ) ? $data['static_checks'] : null;

		/** @var array<string, mixed>|null $runtime_checks */
		$runtime_checks = isset( $data['runtime_checks'] ) && is_array( $data['runtime_checks'] ) ? $data['runtime_checks'] : null;

		/** @var array<string, mixed>|null $judge_config */
		$judge_config = isset( $data['judge_config'] ) && is_array( $data['judge_config'] ) ? $data['judge_config'] : null;

		// Extract and validate string fields.
		$id                = $data['id'];
		$category          = $data['category'];
		$prompt            = $data['prompt'];
		$expected_behavior = $data['expected_behavior'];

		if ( ! is_string( $id ) || ! is_string( $category ) || ! is_string( $prompt ) || ! is_string( $expected_behavior ) ) {
			throw new \InvalidArgumentException( 'Execution test required fields must be strings' );
		}

		$reference_solution = $data['reference_solution'] ?? null;
		$difficulty         = $data['difficulty'] ?? null;

		return new self(
			id: $id,
			category: $category,
			prompt: $prompt,
			expected_behavior: $expected_behavior,
			requirements: $requirements,
			context: $context,
			static_checks: $static_checks,
			runtime_checks: $runtime_checks,
			judge_config: $judge_config,
			reference_solution: is_string( $reference_solution ) ? $reference_solution : null,
			difficulty: is_string( $difficulty ) ? $difficulty : null,
		);
	}

	/**
	 * Get the test identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Get the test category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return $this->category;
	}

	/**
	 * Get the test prompt.
	 *
	 * @return string
	 */
	public function get_prompt(): string {
		return $this->prompt;
	}

	/**
	 * Get the difficulty level.
	 *
	 * @return string|null
	 */
	public function get_difficulty(): ?string {
		return $this->difficulty;
	}

	/**
	 * Get the test type identifier.
	 *
	 * @return string Always 'execution'.
	 */
	public function get_type(): string {
		return Test_Type::EXECUTION->value;
	}

	/**
	 * Get the test type enum.
	 *
	 * @return Test_Type
	 */
	public function get_test_type(): Test_Type {
		return Test_Type::EXECUTION;
	}

	/**
	 * Get the expected behavior description.
	 *
	 * @return string
	 */
	public function get_expected_behavior(): string {
		return $this->expected_behavior;
	}

	/**
	 * Get the list of requirements.
	 *
	 * @return list<string>
	 */
	public function get_requirements(): array {
		return $this->requirements;
	}

	/**
	 * Get the context information.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_context(): ?array {
		return $this->context;
	}

	/**
	 * Get existing code from context, if any.
	 *
	 * @return string|null
	 */
	public function get_existing_code(): ?string {
		if ( null === $this->context || ! isset( $this->context['existing_code'] ) ) {
			return null;
		}
		return is_string( $this->context['existing_code'] ) ? $this->context['existing_code'] : null;
	}

	/**
	 * Get the static check configuration.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_static_checks(): ?array {
		return $this->static_checks;
	}

	/**
	 * Get the runtime check configuration.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_runtime_checks(): ?array {
		return $this->runtime_checks;
	}

	/**
	 * Get the judge configuration.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_judge_config(): ?array {
		return $this->judge_config;
	}

	/**
	 * Get the rubric ID for judge evaluation.
	 *
	 * @return string Default rubric ID if not specified.
	 */
	public function get_rubric_id(): string {
		if ( null === $this->judge_config || ! isset( $this->judge_config['rubric_id'] ) ) {
			return 'wp-judge-rubric-v1';
		}
		return is_string( $this->judge_config['rubric_id'] ) ? $this->judge_config['rubric_id'] : 'wp-judge-rubric-v1';
	}

	/**
	 * Check if judge evaluation is enabled.
	 *
	 * @return bool
	 */
	public function is_judge_enabled(): bool {
		if ( null === $this->judge_config || ! isset( $this->judge_config['enabled'] ) ) {
			return true;
		}
		return (bool) $this->judge_config['enabled'];
	}

	/**
	 * Get any judge context.
	 *
	 * @return string|null
	 */
	public function get_judge_context(): ?string {
		if ( null === $this->judge_config || ! isset( $this->judge_config['context_for_judge'] ) ) {
			return null;
		}
		return is_string( $this->judge_config['context_for_judge'] ) ? $this->judge_config['context_for_judge'] : null;
	}

	/**
	 * Get the reference solution.
	 *
	 * @return string|null
	 */
	public function get_reference_solution(): ?string {
		return $this->reference_solution;
	}

	/**
	 * Check if this test has static checks.
	 *
	 * @return bool
	 */
	public function has_static_checks(): bool {
		return null !== $this->static_checks && ! empty( $this->static_checks );
	}

	/**
	 * Check if this test has runtime checks.
	 *
	 * @return bool
	 */
	public function has_runtime_checks(): bool {
		return null !== $this->runtime_checks
			&& isset( $this->runtime_checks['assertions'] )
			&& is_array( $this->runtime_checks['assertions'] )
			&& ! empty( $this->runtime_checks['assertions'] );
	}

	/**
	 * Convert the test to an array representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$data = [
			'id'                => $this->id,
			'category'          => $this->category,
			'prompt'            => $this->prompt,
			'expected_behavior' => $this->expected_behavior,
		];

		if ( null !== $this->difficulty ) {
			$data['difficulty'] = $this->difficulty;
		}

		if ( ! empty( $this->requirements ) ) {
			$data['requirements'] = $this->requirements;
		}

		if ( null !== $this->context ) {
			$data['context'] = $this->context;
		}

		if ( null !== $this->static_checks ) {
			$data['static_checks'] = $this->static_checks;
		}

		if ( null !== $this->runtime_checks ) {
			$data['runtime_checks'] = $this->runtime_checks;
		}

		if ( null !== $this->judge_config ) {
			$data['judge_config'] = $this->judge_config;
		}

		if ( null !== $this->reference_solution ) {
			$data['reference_solution'] = $this->reference_solution;
		}

		return $data;
	}
}
