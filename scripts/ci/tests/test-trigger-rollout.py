"""Verify rollout retries refresh OIDC tokens without network calls or sleeps."""

import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile


with tempfile.TemporaryDirectory() as directory:
    mock_bin = Path(directory)
    mock_curl = mock_bin / "curl"
    mock_curl.write_text(
        f"#!{sys.executable}\n"
        "import json, os, pathlib, sys\n"
        "log = pathlib.Path(os.environ['MOCK_REQUESTS'])\n"
        "calls = json.loads(log.read_text()) if log.exists() else []\n"
        "calls.append(sys.argv[1:])\n"
        "log.write_text(json.dumps(calls))\n"
        "if sys.argv[-1] == 'https://oidc.example':\n"
        "    count = sum(c[-1] == 'https://oidc.example' for c in calls)\n"
        "    print(json.dumps({'value': f'token-{count}'}))\n"
        "else:\n"
        "    count = sum(c[-1] == 'https://rollout.example' for c in calls)\n"
        "    print('500' if count < 4 else '200')\n"
    )
    mock_curl.chmod(0o755)
    mock_sleep = mock_bin / "sleep"
    mock_sleep.write_text('#!/bin/sh\nprintf "%s\\n" "$1" >> "$MOCK_SLEEPS"\n')
    mock_sleep.chmod(0o755)
    log = mock_bin / "requests.json"
    environment = {
        **os.environ,
        "PATH": f"{mock_bin}:{os.environ['PATH']}",
        "MOCK_REQUESTS": str(log),
        "MOCK_SLEEPS": str(mock_bin / "sleeps"),
        "ACTIONS_ID_TOKEN_REQUEST_TOKEN": "test-request-token",
        "ACTIONS_ID_TOKEN_REQUEST_URL": "https://oidc.example",
        "ROLLOUT_URL": "https://rollout.example",
        "GITHUB_REF_NAME": "main",
    }
    script = Path(__file__).resolve().parents[1] / "trigger-rollout.sh"
    subprocess.run(["bash", str(script)], env=environment, check=True)
    calls = json.loads(log.read_text())
    assert [call[-1] for call in calls] == [
        "https://oidc.example", "https://rollout.example",
    ] * 4
    for attempt in range(1, 5):
        assert f"Authorization: bearer token-{attempt}" in calls[attempt * 2 - 1]
    assert (mock_bin / "sleeps").read_text().splitlines() == [
        "5", "10", "5", "20", "5", "30", "5",
    ]
    print("Rollout retries use fresh OIDC tokens and 10/20/30-second backoff.")
