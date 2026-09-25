#!/usr/bin/env python3
"""Loopback-only CONNECT/TLS fixture. Never opens an upstream connection."""
import http.server
import json
import socketserver
import ssl
import sys
import time

port, certificate, key, metrics = sys.argv[1:]


class Handler(http.server.BaseHTTPRequestHandler):
    def do_CONNECT(self):
        if self.path != "www.sendrepute.com:443":
            self.send_error(403)
            return
        self.send_response(200, "Connection Established")
        self.end_headers()
        self.wfile.flush()
        tls = context.wrap_socket(self.connection, server_side=True)
        try:
            request = tls.makefile("rb")
            line = request.readline().decode("ascii", "replace")
            headers = {}
            while True:
                header = request.readline().decode("ascii", "replace").strip()
                if not header:
                    break
                name, _, value = header.partition(":")
                headers[name.lower()] = value.strip()
            body = request.read(int(headers.get("content-length", "0")))
            valid = (line.startswith("POST /api/v1/classify HTTP/")
                     and headers.get("authorization") == "Bearer local-only-paid-stub"
                     and set(json.loads(body)) == {"sender", "subject", "body"})
            with open(metrics, "a", encoding="utf-8") as evidence:
                evidence.write(json.dumps({"time_ns": time.monotonic_ns(),
                                           "valid": valid}) + "\n")
            time.sleep(1.5)  # Keep the first native request inside real cURL.
            response = {
                "requestId": "local-fixture-only",
                "model": "thor",
                "result": {
                    "label": "inbox", "spamProbability": 0.15,
                    "confidence": "high", "reasons": [], "flaggedTerms": [],
                    "analyzedFields": ["sender", "subject", "body"],
                    "modelVersion": "local-fixture-v1",
                    "analyzedAt": "2026-01-01T00:00:00Z",
                },
                "billing": {"chargedMillicents": 0, "replayed": False},
            }
            payload = json.dumps(response).encode()
            status = b"200 OK" if valid else b"400 Bad Request"
            tls.sendall(b"HTTP/1.1 " + status + b"\r\nContent-Type: application/json\r\n"
                        + b"Content-Length: " + str(len(payload)).encode()
                        + b"\r\nConnection: close\r\n\r\n" + payload)
        finally:
            tls.close()

    def log_message(self, *_args):
        pass


context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(certificate, key)


class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = False


Server(("127.0.0.1", int(port)), Handler).serve_forever()