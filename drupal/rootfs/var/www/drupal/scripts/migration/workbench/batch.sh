#!/usr/bin/env bash

set -eou pipefail

BASE_CONFIG=configs/update.yml

INPUT_CSV="input_data/$(grep input_csv $BASE_CONFIG | awk -F ': ' '{print $2}')"

# Which CSV row to start on
START=1
# if this job was killed, pick up where it left off
if [ -f batch.iter ]; then
    START=$(cat batch.iter)
fi

# how many CSV rows each workbench process should execute at a time
COUNT=100

# how many executions/threads can run at once
PARALLEL_EXECUTIONS=10

# When to stop processing
MAX=$(wc -l $INPUT_CSV | awk '{print $1}')
MAX=$((MAX - 1))

while [ "$START" -lt "$MAX" ]; do
    for ((i = 0; i < PARALLEL_EXECUTIONS; i++)); do
        STOP=$((START+COUNT-1))
        if [ "$STOP" -gt "$MAX" ]; then
            STOP=$MAX
        fi

	BATCH_CONFIG=$BASE_CONFIG-batch-$i.yml
        cp $BASE_CONFIG $BATCH_CONFIG
        echo "log_file_path: logs/i7-batch-$i.log" >> $BATCH_CONFIG
        echo "csv_start_row: $START" >> $BATCH_CONFIG
        echo "csv_stop_row: $STOP" >> $BATCH_CONFIG

        python3 /Users/jjc223/lehigh/islandora_workbench/workbench --config $BATCH_CONFIG &
        job_ids+=($!)

        START=$((START + COUNT))

	# save our place incase we have to run this again
      	echo $START > batch.iter

	# break once we're at the max
        if [ "$START" -gt "$MAX" ]; then
          break
        fi
    done

    echo "Waiting for jobs to complete."

    for job_id in "${job_ids[@]}"; do
        wait "$job_id" || echo "Job failed. Continuing anyway"
    done

    echo "Jobs completed."
done
rm batch.iter
