#!/bin/bash
# Run one PHP regression file against the REL test DB and print uncaught exceptions (bootstrap logs them otherwise).
cd "${2:-/root/stobe-work/social-phase1/server}" || exit 2
STOBE_DB_NAME=stobe_social_phase1_test php -r 'try { require $argv[1]; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), " @", $e->getFile(), ":", $e->getLine(), "\n"; exit(1); }' "$1"
