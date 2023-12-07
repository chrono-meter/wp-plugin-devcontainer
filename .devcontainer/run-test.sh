#!/usr/bin/env bash
pushd "$( dirname -- "${BASH_SOURCE[0]}" )"
docker-compose -f test-docker-compose.yml -f ../test-docker-compose-options.yml rm --force --volumes
time docker-compose -f test-docker-compose.yml -f ../test-docker-compose-options.yml up --build --exit-code-from php
popd
