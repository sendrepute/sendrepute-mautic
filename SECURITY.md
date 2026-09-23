# Security

Report vulnerabilities privately to support@sendrepute.com with version,
impact and a redacted reproduction. Do not include keys, messages, sessions or
personal information. Coordinate disclosure before publishing exploit details.
No response-time SLA is promised. Version 0.1.x is the supported source line.

Use supported Mautic/PHP releases. This plugin targets Mautic 5.x only and a
single POSIX web node with reliable local flock and persistent private ledger
storage. Multi-node installations are unsupported. Protect API credentials and
the ledger directory. Never bypass CSRF, permission or paid-consent checks.