# WP-Bench (Python Edition)

WP-Bench is the official WordPress AI benchmark for labs that expect `pip install`, Docker runtimes, and Hugging Face datasets. The repo now separates concerns cleanly:

- **`python/`** – the harness that loads datasets, prompts models via LiteLLM, calls the WordPress grader, and writes leaderboard-ready results.
- **`runtime/`** – a minimal WordPress plugin (WordPress 6.9 / PHP 8.2) that exposes `wp bench verify` for static + runtime assertions and ships with wp-env + Docker assets.
- **`datasets/`** – canonical suites plus a Hugging Face builder/export scripts.

This layout makes WordPress the **grader** and Python the **orchestrator**, which matches how AI research teams run evals internally.

---

## Repository Layout

```
.
├── python/                 # pyproject, wp_bench package, CLI configs
├── runtime/                # WordPress runtime plugin + Docker/wp-env assets
├── datasets/               # suites, HF builder, export helpers
└── README.md               # you are here
```

---

## Quick Start (AI Researchers)

1. **Install the harness**
   ```bash
   python3 -m venv .venv && source .venv/bin/activate
   pip install -e ./python
   ```

2. **Launch a runtime** (pick one)
   - **wp-env** (local, zero-config):
     ```bash
     cd runtime
     npm install --global @wordpress/env
     npx wp-env start   # boots WordPress 6.9 with the runtime plugin mounted
     ```
   - **Docker image** (self-contained CLI grader):
     ```bash
     cd runtime
     docker build -t wp-bench-grader:dev .

     docker network create wp-bench
     docker run -d --name wp-bench-mysql --network wp-bench \
       -e MYSQL_DATABASE=wordpress -e MYSQL_ROOT_PASSWORD=password mysql:8.0

     HARBOR_URL=http://localhost \\
     docker run -d --name wp-bench-grader --network wp-bench \
       -e WORDPRESS_DB_HOST=wp-bench-mysql \
       -e WORDPRESS_DB_PASSWORD=password \
       -e WORDPRESS_SITE_URL=${HARBOR_URL} \
       wp-bench-grader:dev
     ```

3. **Run the benchmark**
   ```bash
   wp-bench run --config python/wp-bench.example.yaml
   ```

   The harness will load suites (local JSON or Hugging Face), call your model via LiteLLM, pipe generated code to `wp bench verify`, and emit `results.json` + `results.jsonl`. Use CLI flags (`--suite`, `--model-name`, `--limit`, etc.) for ad-hoc overrides.

---

## Datasets

Canonical suites now live under `datasets/suites/<suite>/execution.json` + `knowledge.json`. They contain the same metadata as before but are organized for publishing.

- Hugging Face builder: `datasets/wp_bench.py`
- Quick export helper: `datasets/export_dataset.py`
- Documentation: `datasets/README.md`
- The harness defaults to loading `WordPress/wp-bench-v1` from the Hugging Face Hub; set `dataset.source: local` if you want to consume the checked-in JSON instead.

Load suites locally in Python:

```python
from datasets import load_dataset
suite = load_dataset("datasets/wp_bench.py", name="wp-core-v1", split="test")
```

Publishing flow:

1. Update JSON in `datasets/suites/` (bump version metadata).
2. Run `python datasets/export_dataset.py` if you need bundled artifacts.
3. Use `huggingface-cli upload WordPress/wp-bench-v1 datasets/` to push builder + suites.

---

## WordPress Runtime

The runtime is intentionally minimal:

- Plugin bootstrap: `runtime/wp-bench-runtime.php`
- Static analysis: `runtime/src/class-static-analysis.php`
- Sandbox executor: `runtime/src/class-sandbox.php`
- WP-CLI entrypoint: `runtime/src/class-cli-verifier.php`
- Environment configs: `runtime/.wp-env.json`, `runtime/Dockerfile`, `runtime/docker-entrypoint.sh`

It expects Base64-encoded JSON payloads:

```bash
npx wp-env run cli \
  wp bench verify \
    --payload=$(printf '%s' '{"code":"<?php echo \"hi\";"}' | base64)
```

Response shape:

```json
{
  "success": false,
  "static": {"score": 0.5, ...},
  "runtime": {"score": 0.0, ...},
  "assertions": [...]
}
```

The Python harness interprets that JSON to compute correctness/quality metrics.

---

## Harness Configuration

`python/wp-bench.example.yaml` demonstrates the new settings:

```yaml
suite: wp-core-v1
dataset:
  source: local
  name: wp-core-v1
model:
  kind: openai
  name: gpt-4o-mini
grader:
  kind: docker
  image: ghcr.io/wordpress/wp-bench-grader:latest
  container_name: wp-bench-grader
  wp_env_dir: ./runtime
output:
  path: results.json
run:
  limit: 5
```

Key knobs:

- `dataset.source`: `local` (reads `datasets/suites`) or `huggingface` (requires `DatasetConfig.name` = `org/dataset`).
- `grader.kind`: `docker`, `cli`, or `http`. Provide `wp_env_dir` to drive `npx wp-env run` automatically.
- `run.*`: `limit`, `skip_judge`, `skip_runtime`, etc. for quick experiments.
- `output.jsonl_path`: per-test streaming logs.

Install dev tooling with `pip install -e ./python[dev]` for `ruff`, `mypy`, and `pytest`.

## Roadmap

1. Publish the Docker grader image to `ghcr.io/wordpress/wp-bench-grader`.
2. Extend model adapters (Anthropic, Ollama, OpenAI-compatible) using LiteLLM router semantics.
3. Add caching + retries for verifier calls, plus concurrent execution.
4. Introduce modern task kinds (`block_plugin`, `js_module`) with Playwright/Jest assertions.
5. Maintain public + private HF suite revisions for contamination control.
