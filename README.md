# WP AI Benchmarks

A WordPress plugin that benchmarks LLM performance on WordPress-specific knowledge and code generation tasks. Runs via WP-CLI in a wp-env Docker container.

## Features

- **Knowledge Tests**: MMLU-style multiple choice and short answer questions covering WordPress APIs, hooks, security, REST API, and more
- **Execution Tests**: HumanEval-style code generation tasks with three-layer evaluation:
  - Static analysis (pattern matching)
  - Runtime verification (actual code execution)
  - AI judge (quality assessment using LLM-as-judge)
- **Multiple Providers**: Supports OpenAI, Anthropic, Google, Mistral, and Cohere via WP AI Client
- **Detailed Scoring**: Category breakdowns, per-test results, and statistical analysis for multiple runs

## Requirements

- PHP 8.4+
- WordPress 6.9+
- Node.js 18+ (for wp-env)
- Docker (for wp-env)

## Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/wordpress/wp-ai-benchmarks.git
   cd wp-ai-benchmarks
   ```

2. Install dependencies:
   ```bash
   composer install
   npm install
   ```

3. Configure API keys (see [Configuration](#configuration))

4. Start the environment:
   ```bash
   npx wp-env start
   ```

## Configuration

API keys are configured via `.wp-env.override.json`, which defines PHP constants injected into wp-config.php.

1. Copy the example file:
   ```bash
   cp .wp-env.override.json.example .wp-env.override.json
   ```

2. Add your API keys:
   ```json
   {
     "$schema": "https://schemas.wp.org/trunk/wp-env.json",
     "config": {
       "OPENAI_API_KEY": "sk-...",
       "ANTHROPIC_API_KEY": "sk-ant-...",
       "GOOGLE_API_KEY": "..."
     }
   }
   ```

3. Restart wp-env to apply:
   ```bash
   npx wp-env stop && npx wp-env start
   ```

Supported providers: `openai`, `anthropic`, `google`, `mistral`, `cohere`

## Usage

### Run a Full Benchmark

```bash
npx wp-env run cli wp ai-bench run --suite=wp-core-v1 --model=anthropic:claude-sonnet-4-20250514
```

With options:
```bash
npx wp-env run cli wp ai-bench run \
  --suite=wp-core-v1 \
  --model=openai:gpt-4.1 \
  --judge-model=anthropic:claude-sonnet-4-20250514 \
  --runs=3 \
  --verbose
```

**Options:**
- `--suite` (required): Test suite name (e.g., `wp-core-v1`)
- `--model` (required): Model to test in `provider:model` format
- `--judge-model`: Model for quality evaluation (defaults to `--model`)
- `--runs`: Number of runs for statistical averaging (default: 1)
- `--concurrency`: Number of parallel test workers (default: 5)
- `--verbose`: Show detailed per-test results
- `--format`: Output format (`table` or `json`)
- `--skip-judge`: Skip AI judge evaluation (faster, but no quality scores)

### Parallel Execution

By default, tests run with 5 concurrent workers for faster execution:

```bash
# Use 10 workers (faster, may hit API rate limits)
npx wp-env run cli wp ai-bench run --suite=wp-core-v1 --model=anthropic:claude-sonnet-4-20250514 --concurrency=10

# Run sequentially (slower, guaranteed order)
npx wp-env run cli wp ai-bench run --suite=wp-core-v1 --model=anthropic:claude-sonnet-4-20250514 --concurrency=1
```

### List Available Suites

```bash
npx wp-env run cli wp ai-bench list
```

### Run a Single Test

```bash
npx wp-env run cli wp ai-bench run-test k-hooks-001 --model=anthropic:claude-sonnet-4-20250514
```

### Show Test Details

```bash
npx wp-env run cli wp ai-bench show-test e-shortcode-001
```

## Scoring System

### Score Components

| Score | Weight | Description |
|-------|--------|-------------|
| Knowledge | 30% | Accuracy on knowledge tests (0 or 1 per test) |
| Execution Correctness | 40% | Average of static + runtime scores |
| Execution Quality | 30% | AI judge assessment of code quality |

### Overall Score Calculation

```
overall = 0.3 * knowledge + 0.4 * correctness + 0.3 * quality
```

### Knowledge Test Scoring

- **Multiple choice**: Exact letter match = 1.0, otherwise 0.0
- **Short answer**: Supports exact match, regex, or contains matching

### Execution Test Scoring

Each execution test is evaluated on three layers:

1. **Static Score**: Pattern matching for required/forbidden code patterns
2. **Runtime Score**: Actual execution with assertions (function exists, output matches, etc.)
3. **Quality Score**: LLM judge evaluation based on rubric criteria (security, performance, conventions, readability)

## Test Suite Structure

Test suites are JSON files in the `tests/` directory:

```
tests/
├── knowledge/
│   └── wp-core-v1.json    # Knowledge questions
└── execution/
    └── wp-core-v1.json    # Code generation tasks
```

### Knowledge Test Format

```json
{
  "id": "k-hooks-001",
  "category": "hooks",
  "prompt": "Which WordPress hook fires after all plugins have been loaded?",
  "type": "multiple_choice",
  "choices": [
    { "key": "A", "text": "init" },
    { "key": "B", "text": "plugins_loaded" }
  ],
  "correct_answer": "B"
}
```

### Execution Test Format

```json
{
  "id": "e-shortcode-001",
  "category": "shortcodes",
  "prompt": "Create a WordPress shortcode called 'greeting' that accepts a 'name' attribute...",
  "expected_behavior": "Shortcode outputs personalized greeting",
  "requirements": [
    "Use add_shortcode() function",
    "Handle missing name attribute with default"
  ],
  "static_checks": {
    "required_patterns": [
      { "pattern": "add_shortcode", "weight": 1.0 }
    ]
  },
  "runtime_checks": {
    "setup": "// Setup code run before test",
    "assertions": [
      { "type": "shortcode_exists", "shortcode": "greeting" }
    ]
  }
}
```

## Judge Rubrics

Judge rubrics define evaluation criteria for code quality assessment:

```
judges/
└── wp-judge-rubric-v1.json
```

Default criteria:
- **Security** (30%): Input validation, escaping, nonce usage
- **Performance** (20%): Query efficiency, caching, avoiding unnecessary operations
- **Conventions** (25%): WordPress coding standards, naming conventions
- **Readability** (15%): Code clarity, documentation, organization
- **Correctness** (10%): Functional accuracy beyond basic tests

## Example Results

```
========================================
BENCHMARK RESULTS
========================================

Suite: wp-core-v1
Model: anthropic:claude-sonnet-4-20250514
Judge: anthropic:claude-sonnet-4-20250514
Runs: 1
Duration: 45.32s
Tests: 17 total (12 knowledge, 5 execution)

SCORES:

  Category              Knowledge      Execution          Total
  ------------------------------------------------------------
  hooks                 1.00 (3)       0.95 (2)       0.98 (5)
  queries               1.00 (2)              -       1.00 (2)
  security              1.00 (2)       0.90 (1)       0.97 (3)
  rest-api              1.00 (3)       0.85 (1)       0.96 (4)
  shortcodes                   -       1.00 (1)       1.00 (1)

  TOTAL                 1.00 (12)      0.98 (5)       0.96 (17)
```

The scores table shows performance by category, split by test type (knowledge questions vs. code generation). This makes it easy to identify areas where the model excels or needs improvement.

## Development

### Code Quality

```bash
# Run linter
composer lint

# Fix linting issues
composer lint:fix

# Run static analysis
composer phpstan

# Run all checks
composer test
```

### Adding New Tests

1. Create or edit a JSON file in `tests/knowledge/` or `tests/execution/`
2. Follow the test format schema
3. Run the benchmark to verify

### Project Structure

```
wp-ai-benchmarks/
├── wp-ai-benchmarks.php           # Plugin bootstrap
├── inc/
│   ├── class-ai-bench-runner.php           # Benchmark orchestrator
│   ├── class-ai-bench-suite-loader.php     # Test suite loader
│   ├── class-ai-bench-test-result.php      # Result value object
│   ├── class-ai-bench-executor-knowledge.php  # Knowledge test executor
│   ├── class-ai-bench-executor-execution.php  # Execution test executor
│   ├── class-ai-bench-judge.php            # LLM-as-judge
│   ├── class-ai-bench-static-checker.php   # Static analysis
│   ├── class-ai-bench-environment.php      # Runtime sandbox
│   └── class-ai-bench-model-client.php     # WP AI Client wrapper
├── cli/
│   └── class-ai-bench-command.php          # WP-CLI commands
├── tests/
│   ├── knowledge/                          # Knowledge test suites
│   └── execution/                          # Execution test suites
├── judges/
│   └── wp-judge-rubric-v1.json            # Judge evaluation rubric
└── mu-plugins/
    └── ai-bench-credentials.php           # API key injection
```

## License

GPL-2.0-or-later
