# WP-Bench Datasets

This directory contains the canonical suites and tooling for uploading them to the Hugging Face `datasets` Hub.

- `suites/<suite>/execution.json` / `knowledge.json` – source of truth for each benchmark suite.
- `wp_bench.py` – Hugging Face builder that loads those files directly.
- `export_dataset.py` – convenience script for bundling suites prior to upload.

## Local development

```bash
python -m datasets.load_dataset datasets/wp_bench.py --name wp-core-v1 --split test
```

Publishing is handled via `datasets-cli`:

```bash
huggingface-cli upload WordPress/wp-bench-v1 datasets/
```
