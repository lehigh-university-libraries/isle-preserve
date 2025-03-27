#!/usr/bin/env bash

ENVS=( wight.cc.lehigh.edu islandora-test.lib.lehigh.edu islandora-prod.lib.lehigh.edu )
for ENV in "${ENVS[@]}"; do
  echo "docker compose up on $ENV"
  ssh $ENV -t "sudo systemctl start islandora; sudo journalctl -u islandora | tail -50"
done
