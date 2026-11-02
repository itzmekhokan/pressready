# Security Policy

## Supported versions

Only the latest `1.x` release receives security fixes. Upgrade to the newest
release before reporting.

## Reporting a vulnerability

Please **do not open a public issue**. Report it privately through GitHub:
[Security → Report a vulnerability](https://github.com/itzmekhokan/pressready/security/advisories/new).

Include the affected version, how to reproduce it, and the impact you expect.
You should get an acknowledgement within 7 days. Once a fix is released, the
advisory is published with credit to you unless you ask otherwise.

In scope: the `pressready` CLI, the PHAR, the Docker image, the GitHub Action,
and this repository's release workflows. Findings PressReady reports about
*your* code are not vulnerabilities in PressReady.

## Verifying releases

From 1.7.0, the PHAR and Docker image carry build-provenance attestations and
the image is signed with cosign. See
[Security & verifying releases](README.md#security--verifying-releases) for the
commands.
