"""Utility to mirror local JSON suites into Hugging Face dataset JSONL files."""
from __future__ import annotations

from pathlib import Path

import orjson

DATASETS_DIR = Path(__file__).resolve().parent
OUTPUT_DIR = DATASETS_DIR / "exports"
OUTPUT_DIR.mkdir(parents=True, exist_ok=True)


def main() -> None:
    suite_dir = DATASETS_DIR / "suites" / "wp-core-v1"
    execution = (suite_dir / "execution.json").read_bytes()
    knowledge = (suite_dir / "knowledge.json").read_bytes()
    combined = {
        "execution": orjson.loads(execution),
        "knowledge": orjson.loads(knowledge),
    }
    (OUTPUT_DIR / "wp-core-v1.json").write_bytes(orjson.dumps(combined, option=orjson.OPT_INDENT_2))
    print(f"Exported dataset to {OUTPUT_DIR}")


if __name__ == "__main__":
    main()
