"""Main orchestration loop for WP-Bench."""
from __future__ import annotations

from typing import Any, Dict, List

import orjson
from rich.console import Console
from rich.progress import track

from .config import HarnessConfig
from .datasets import ExecutionTest, KnowledgeTest, load_tests
from .environment import WordPressEnvironment
from .models import ModelInterface
from .scoring import ScoreAggregator
from .utils import ensure_dir, sha256, strip_code_fences

console = Console()


class BenchmarkRunner:
    def __init__(self, config: HarnessConfig):
        self.config = config
        self.model = ModelInterface(config.model)
        self.environment = WordPressEnvironment(config.grader)
        self.aggregator = ScoreAggregator()
        self.records: List[Dict[str, Any]] = []

    def run(self) -> Dict[str, Any]:
        tests = load_tests(self.config.dataset)
        self.environment.setup()
        self._run_knowledge_tests(tests["knowledge"])
        self._run_execution_tests(tests["execution"])
        summary = self.aggregator.finalize()
        payload = {
            "metadata": {
                "suite": self.config.run.suite,
                "model": self.config.model.dict(),
                "grader": self.config.grader.dict(),
                "dataset": self.config.dataset.dict(),
                "scores": {
                    "knowledge": summary.knowledge,
                    "correctness": summary.correctness,
                    "quality": summary.quality,
                    "overall": summary.overall(),
                },
            },
            "results": self.records,
        }
        self._write_outputs(payload)
        return payload

    # Internal helpers --------------------------------------------------
    def _run_knowledge_tests(self, tests: List[KnowledgeTest]) -> None:
        limit = self.config.run.limit or len(tests)
        for test in track(tests[:limit], description="Knowledge"):
            messages = ModelInterface.to_messages(
                "You are an expert WordPress developer.",
                self._render_knowledge_prompt(test),
            )
            answer = strip_code_fences(self.model.generate(messages)).strip()
            correct = 1.0 if (test.correct_answer and answer.upper().startswith(test.correct_answer)) else 0.0
            self.aggregator.add_knowledge(correct)
            self.records.append(
                {
                    "test_id": test.id,
                    "type": "knowledge",
                    "prompt_hash": sha256(messages[-1]["content"]),
                    "answer": answer,
                    "correct": bool(correct),
                }
            )

    def _run_execution_tests(self, tests: List[ExecutionTest]) -> None:
        limit = self.config.run.limit or len(tests)
        for test in track(tests[:limit], description="Execution"):
            messages = ModelInterface.to_messages(
                "You are an expert WordPress core contributor.",
                self._render_execution_prompt(test),
            )
            completion = self.model.generate(messages)
            code = strip_code_fences(completion)
            verification_spec = {
                "static_checks": test.static_checks,
                "runtime_checks": test.runtime_checks,
                "judge_config": test.judge_config,
            }
            env_result = self.environment.execute_code(code, verification_spec)
            correctness = self._score_assertions(env_result.raw)
            quality = env_result.raw.get("quality", {}).get("score") if env_result.raw else None
            self.aggregator.add_execution(correctness, quality)
            self.records.append(
                {
                    "test_id": test.id,
                    "type": "execution",
                    "prompt_hash": sha256(messages[-1]["content"]),
                    "code": code,
                    "result": env_result.raw,
                    "stdout": env_result.stdout,
                    "stderr": env_result.stderr,
                    "correctness": correctness,
                    "quality": quality,
                }
            )

    @staticmethod
    def _render_knowledge_prompt(test: KnowledgeTest) -> str:
        prompt = [test.prompt]
        if test.choices:
            prompt.append("Choices:")
            for choice in test.choices:
                prompt.append(f"{choice['key']}. {choice['text']}")
        prompt.append("Answer with only the letter of the correct choice.")
        return "\n".join(prompt)

    @staticmethod
    def _render_execution_prompt(test: ExecutionTest) -> str:
        lines = [test.prompt, "", "Requirements:"]
        for req in test.requirements:
            lines.append(f"- {req}")
        lines.append(
            "Return only valid PHP code without explanations. Wrap the response in ```php fences."
        )
        return "\n".join(lines)

    @staticmethod
    def _score_assertions(raw: Dict[str, Any]) -> float:
        assertions = raw.get("assertions") or []
        if not assertions:
            return 0.0
        passed = sum(1 for assertion in assertions if assertion.get("passed"))
        return round(passed / len(assertions), 4)

    def _write_outputs(self, payload: Dict[str, Any]) -> None:
        ensure_dir(self.config.output.path.parent)
        self.config.output.path.write_bytes(orjson.dumps(payload, option=orjson.OPT_INDENT_2))
        if self.config.output.jsonl_path:
            ensure_dir(self.config.output.jsonl_path.parent)
            with self.config.output.jsonl_path.open("w", encoding="utf-8") as handle:
                for record in self.records:
                    handle.write(orjson.dumps(record).decode("utf-8"))
                    handle.write("\n")
