<?php
/**
 * Knowledge test value object.
 *
 * @package WordPress\AI_Benchmark\Test
 *
 * @phpstan-type Choice array{key: string, text: string}
 */

declare(strict_types=1);

namespace WordPress\AI_Benchmark\Test;

use WordPress\AI_Benchmark\Test\Enum\Answer_Type;
use WordPress\AI_Benchmark\Test\Enum\Question_Type;
use WordPress\AI_Benchmark\Test\Enum\Test_Type;

/**
 * Value object representing a knowledge test (MMLU-style).
 *
 * Supports multiple choice and short answer questions with
 * exact, regex, or contains matching.
 */
final class Knowledge_Test implements Test_Interface {

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
	 * Test subcategory.
	 *
	 * @var string|null
	 */
	private readonly ?string $subcategory;

	/**
	 * Difficulty level.
	 *
	 * @var string|null
	 */
	private readonly ?string $difficulty;

	/**
	 * Question prompt.
	 *
	 * @var string
	 */
	private readonly string $prompt;

	/**
	 * Question type.
	 *
	 * @var Question_Type
	 */
	private readonly Question_Type $question_type;

	/**
	 * Multiple choice options.
	 *
	 * @var list<array{key: string, text: string}>|null
	 */
	private readonly ?array $choices;

	/**
	 * Correct answer.
	 *
	 * @var string
	 */
	private readonly string $correct_answer;

	/**
	 * Answer matching type.
	 *
	 * @var Answer_Type
	 */
	private readonly Answer_Type $answer_type;

	/**
	 * Explanation for the correct answer.
	 *
	 * @var string|null
	 */
	private readonly ?string $explanation;

	/**
	 * Reference URLs.
	 *
	 * @var list<string>
	 */
	private readonly array $references;

	/**
	 * Constructor.
	 *
	 * @param string                                      $id             Test identifier.
	 * @param string                                      $category       Test category.
	 * @param string                                      $prompt         Question prompt.
	 * @param Question_Type                               $question_type  Question type.
	 * @param string                                      $correct_answer Correct answer.
	 * @param list<array{key: string, text: string}>|null $choices    Multiple choice options.
	 * @param Answer_Type                                 $answer_type    Answer matching type.
	 * @param string|null                                 $subcategory    Test subcategory.
	 * @param string|null                                 $difficulty     Difficulty level.
	 * @param string|null                                 $explanation    Answer explanation.
	 * @param list<string>                                $references     Reference URLs.
	 */
	public function __construct(
		string $id,
		string $category,
		string $prompt,
		Question_Type $question_type,
		string $correct_answer,
		?array $choices = null,
		Answer_Type $answer_type = Answer_Type::EXACT,
		?string $subcategory = null,
		?string $difficulty = null,
		?string $explanation = null,
		array $references = [],
	) {
		$this->id             = $id;
		$this->category       = $category;
		$this->prompt         = $prompt;
		$this->question_type  = $question_type;
		$this->correct_answer = $correct_answer;
		$this->choices        = $choices;
		$this->answer_type    = $answer_type;
		$this->subcategory    = $subcategory;
		$this->difficulty     = $difficulty;
		$this->explanation    = $explanation;
		$this->references     = $references;
	}

	/**
	 * Create a Knowledge_Test from an array.
	 *
	 * @param array<string, mixed> $data Test data from JSON.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException If required fields are missing or invalid.
	 */
	public static function from_array( array $data ): self {
		$required = [ 'id', 'category', 'prompt', 'type', 'correct_answer' ];
		foreach ( $required as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Knowledge test missing required field: %s', esc_html( $field ) )
				);
			}
		}

		$type_value = $data['type'];
		if ( ! is_string( $type_value ) ) {
			throw new \InvalidArgumentException( 'Knowledge test type must be a string' );
		}
		$question_type = Question_Type::tryFrom( $type_value );
		if ( null === $question_type ) {
			throw new \InvalidArgumentException(
				sprintf( 'Invalid question type: %s', esc_html( $type_value ) )
			);
		}

		if ( Question_Type::MULTIPLE_CHOICE === $question_type && ! isset( $data['choices'] ) ) {
			throw new \InvalidArgumentException(
				"Multiple choice test missing 'choices' field."
			);
		}

		$answer_type_value  = $data['answer_type'] ?? 'exact';
		$answer_type_string = is_string( $answer_type_value ) ? $answer_type_value : 'exact';
		$answer_type        = Answer_Type::tryFrom( $answer_type_string ) ?? Answer_Type::EXACT;

		/** @var list<array{key: string, text: string}>|null $choices */
		$choices = isset( $data['choices'] ) && is_array( $data['choices'] ) ? $data['choices'] : null;

		/** @var list<string> $references */
		$references = isset( $data['references'] ) && is_array( $data['references'] ) ? $data['references'] : [];

		// Extract and validate string fields.
		$id             = $data['id'];
		$category       = $data['category'];
		$prompt         = $data['prompt'];
		$correct_answer = $data['correct_answer'];

		if ( ! is_string( $id ) || ! is_string( $category ) || ! is_string( $prompt ) || ! is_string( $correct_answer ) ) {
			throw new \InvalidArgumentException( 'Knowledge test required fields must be strings' );
		}

		$subcategory = $data['subcategory'] ?? null;
		$difficulty  = $data['difficulty'] ?? null;
		$explanation = $data['explanation'] ?? null;

		return new self(
			id: $id,
			category: $category,
			prompt: $prompt,
			question_type: $question_type,
			correct_answer: $correct_answer,
			choices: $choices,
			answer_type: $answer_type,
			subcategory: is_string( $subcategory ) ? $subcategory : null,
			difficulty: is_string( $difficulty ) ? $difficulty : null,
			explanation: is_string( $explanation ) ? $explanation : null,
			references: $references,
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
	 * Get the test subcategory.
	 *
	 * @return string|null
	 */
	public function get_subcategory(): ?string {
		return $this->subcategory;
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
	 * @return string Always 'knowledge'.
	 */
	public function get_type(): string {
		return Test_Type::KNOWLEDGE->value;
	}

	/**
	 * Get the test type enum.
	 *
	 * @return Test_Type
	 */
	public function get_test_type(): Test_Type {
		return Test_Type::KNOWLEDGE;
	}

	/**
	 * Get the question type as string.
	 *
	 * @return string 'multiple_choice' or 'short_answer'.
	 */
	public function get_question_type(): string {
		return $this->question_type->value;
	}

	/**
	 * Get the question type enum.
	 *
	 * @return Question_Type
	 */
	public function get_question_type_enum(): Question_Type {
		return $this->question_type;
	}

	/**
	 * Get the multiple choice options.
	 *
	 * @return list<array{key: string, text: string}>|null Choices or null for short answer.
	 */
	public function get_choices(): ?array {
		return $this->choices;
	}

	/**
	 * Get the correct answer.
	 *
	 * @return string
	 */
	public function get_correct_answer(): string {
		return $this->correct_answer;
	}

	/**
	 * Get the answer matching type as string.
	 *
	 * @return string 'exact', 'regex', or 'contains'.
	 */
	public function get_answer_type(): string {
		return $this->answer_type->value;
	}

	/**
	 * Get the answer type enum.
	 *
	 * @return Answer_Type
	 */
	public function get_answer_type_enum(): Answer_Type {
		return $this->answer_type;
	}

	/**
	 * Get the answer explanation.
	 *
	 * @return string|null
	 */
	public function get_explanation(): ?string {
		return $this->explanation;
	}

	/**
	 * Get the reference URLs.
	 *
	 * @return list<string>
	 */
	public function get_references(): array {
		return $this->references;
	}

	/**
	 * Check if this is a multiple choice question.
	 *
	 * @return bool
	 */
	public function is_multiple_choice(): bool {
		return Question_Type::MULTIPLE_CHOICE === $this->question_type;
	}

	/**
	 * Check if this is a short answer question.
	 *
	 * @return bool
	 */
	public function is_short_answer(): bool {
		return Question_Type::SHORT_ANSWER === $this->question_type;
	}

	/**
	 * Convert the test to an array representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$data = [
			'id'             => $this->id,
			'category'       => $this->category,
			'prompt'         => $this->prompt,
			'type'           => $this->question_type->value,
			'correct_answer' => $this->correct_answer,
			'answer_type'    => $this->answer_type->value,
		];

		if ( null !== $this->subcategory ) {
			$data['subcategory'] = $this->subcategory;
		}

		if ( null !== $this->difficulty ) {
			$data['difficulty'] = $this->difficulty;
		}

		if ( null !== $this->choices ) {
			$data['choices'] = $this->choices;
		}

		if ( null !== $this->explanation ) {
			$data['explanation'] = $this->explanation;
		}

		if ( ! empty( $this->references ) ) {
			$data['references'] = $this->references;
		}

		return $data;
	}
}
