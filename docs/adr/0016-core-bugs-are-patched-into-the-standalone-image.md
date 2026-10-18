# ADR-0016: Core bugs are patched into the standalone image until upstream ships the fix

*Status: accepted · 2026-09-29*

## Context

Some CiviCRM core bugs get in the way of extension development and testing on
the standalone image. One example is word replacements that never apply on
Standalone. The fix belongs upstream, but the stack is needed before a release
contains it.

## Decision

The standalone image applies one patch file per bug from
`docker/standalone/core-patches/` at build time, in name order.

Every patch names its upstream issue or PR on an `Upstream:` line. A patch
without one fails the build.

A patch that the release already contains is skipped. A patch that neither
applies nor is contained fails the build.

A patch for a bug that a later release introduced names that release on a
`Since: X.Y.Z` line. On an older core, read from `xml/version.xml`, the patch
is skipped as not applicable.

`/usr/local/share/civikitchen/core-patches.log` lists what the image applied.

## Rationale

A dev stack that reproduces a known core bug costs every extension time and
produces false test failures.

The `Upstream:` link ties every patch to an upstream report, so each patch has
a known way out of the directory.

Skipping contained patches lets a patch retire itself when the release ships
the fix. Failing on a mismatch stops a patch from silently not applying after
core moves.

## Consequences

A site running the unpatched release still shows the bug, so a test that
passes on the image can fail in production. Extensions must not rely on a
patched behaviour.

Only the standalone dev image is patched. The CMS images and the demo images,
including `:standalone-demo`, run core as released.
