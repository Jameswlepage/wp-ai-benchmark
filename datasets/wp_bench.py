"""Hugging Face dataset builder for WP-Bench."""
from __future__ import annotations

from pathlib import Path
from typing import Any, Dict, Iterable, Tuple

import datasets
import orjson

DATASETS_DIR = Path(__file__).resolve().parent
SUITES_DIR = DATASETS_DIR / "suites"


def _suite_path(kind: str, suite: str) -> Path:
    return SUITES_DIR / suite / f"{kind}.json"


class WPBenchConfig(datasets.BuilderConfig):
    def __init__(self, suite: str, **kwargs):
        super().__init__(version=datasets.Version("1.0.0"), **kwargs)
        self.suite = suite


class WPBench(datasets.GeneratorBasedBuilder):
    BUILDER_CONFIGS = [
        WPBenchConfig(name="wp-core-v1", suite="wp-core-v1", description="WordPress core benchmark"),
    ]

    def _info(self) -> datasets.DatasetInfo:
        features = datasets.Features(
            {
                "id": datasets.Value("string"),
                "suite": datasets.Value("string"),
                "test_kind": datasets.ClassLabel(names=["execution", "knowledge"]),
                "prompt": datasets.Value("string"),
                "requirements": datasets.Sequence(datasets.Value("string")),
                "category": datasets.Value("string"),
                "difficulty": datasets.Value("string"),
                "choices": datasets.Sequence(
                    {
                        "key": datasets.Value("string"),
                        "text": datasets.Value("string"),
                    }
                ),
                "correct_answer": datasets.Value("string"),
                "static_checks": datasets.Value("string"),
                "runtime_checks": datasets.Value("string"),
                "judge_config": datasets.Value("string"),
                "reference_solution": datasets.Value("string"),
                "metadata": datasets.Value("string"),
            }
        )
        return datasets.DatasetInfo(description="WP-Bench suites", features=features)

    def _split_generators(self, dl_manager: datasets.DownloadManager):
        suite = self.config.suite
        return [
            datasets.SplitGenerator(
                name=datasets.Split.TEST,
                gen_kwargs={"suite": suite},
            )
        ]

    def _generate_examples(self, suite: str) -> Iterable[Tuple[str, Dict[str, Any]]]:
        execution_data = _read_json(_suite_path("execution", suite))
        knowledge_data = _read_json(_suite_path("knowledge", suite))
        metadata = execution_data.get("metadata", {})
        for test in execution_data.get("tests", []):
            yield test["id"], {
                "id": test["id"],
                "suite": suite,
                "test_kind": "execution",
                "prompt": test["prompt"],
                "requirements": test.get("requirements", []),
                "category": test.get("category", "general"),
                "difficulty": test.get("difficulty", "unknown"),
                "choices": [],
                "correct_answer": "",
                "static_checks": orjson.dumps(test.get("static_checks", {})).decode("utf-8"),
                "runtime_checks": orjson.dumps(test.get("runtime_checks", {})).decode("utf-8"),
                "judge_config": orjson.dumps(test.get("judge_config", {})).decode("utf-8"),
                "reference_solution": test.get("reference_solution", ""),
                "metadata": orjson.dumps(metadata).decode("utf-8"),
            }
        for test in knowledge_data.get("tests", []):
            yield test["id"], {
                "id": test["id"],
                "suite": suite,
                "test_kind": "knowledge",
                "prompt": test["prompt"],
                "requirements": [],
                "category": test.get("category", "general"),
                "difficulty": test.get("difficulty", "unknown"),
                "choices": test.get("choices", []),
                "correct_answer": test.get("correct_answer", ""),
                "static_checks": "{}",
                "runtime_checks": "{}",
                "judge_config": "{}",
                "reference_solution": "",
                "metadata": orjson.dumps(knowledge_data.get("metadata", {})).decode("utf-8"),
            }


def _read_json(path: Path) -> Dict[str, Any]:
    with path.open("rb") as handle:
        return orjson.loads(handle.read())
