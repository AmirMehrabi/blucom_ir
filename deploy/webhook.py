#!/usr/bin/env python3
"""Small, authenticated GitHub push receiver for the deployment host."""

import hashlib
import hmac
import json
import os
import re
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

BASE = Path('/var/www/html/blucom-deploy')
SECRET = (BASE / 'shared/webhook-secret').read_bytes().strip()
DEPLOY = BASE / 'current/deploy/deploy.sh'
REPO = 'AmirMehrabi/blucom_ir'
MAX_BODY = 1024 * 1024


class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        if self.path != '/internal/github-deploy':
            self.send_error(404)
            return
        try:
            size = int(self.headers.get('Content-Length', ''))
        except ValueError:
            self.send_error(400)
            return
        if not 0 < size <= MAX_BODY:
            self.send_error(413)
            return
        body = self.rfile.read(size)
        expected = 'sha256=' + hmac.new(SECRET, body, hashlib.sha256).hexdigest()
        if not hmac.compare_digest(self.headers.get('X-Hub-Signature-256', ''), expected):
            self.send_error(401)
            return
        try:
            payload = json.loads(body)
        except json.JSONDecodeError:
            self.send_error(400)
            return

        if self.headers.get('X-GitHub-Event') != 'push':
            self.reply(204)
            return
        repository = payload.get('repository') or {}
        sha = payload.get('after', '')
        valid = (
            payload.get('ref') == 'refs/heads/master'
            and payload.get('deleted') is False
            and repository.get('full_name') == REPO
            and re.fullmatch(r'[0-9a-f]{40}', sha) is not None
        )
        if not valid:
            self.reply(204)
            return

        log = BASE / 'logs' / 'deploy.log'
        with log.open('ab') as output:
            subprocess.Popen([str(DEPLOY), sha], stdin=subprocess.DEVNULL,
                             stdout=output, stderr=subprocess.STDOUT,
                             start_new_session=True, close_fds=True)
        self.reply(202)

    def reply(self, status):
        self.send_response(status)
        self.send_header('Content-Length', '0')
        self.end_headers()

    def do_GET(self):
        self.send_error(405)

    def log_message(self, format, *args):
        # Do not log request headers, webhook payloads, or the shared secret.
        pass


if __name__ == '__main__':
    ThreadingHTTPServer(('127.0.0.1', 9087), Handler).serve_forever()
