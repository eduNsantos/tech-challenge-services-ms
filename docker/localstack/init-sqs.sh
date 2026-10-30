#!/bin/bash
set -e

create_fifo() {
  local name=$1 dlq="$1-dlq.fifo"
  awslocal sqs create-queue --queue-name "$dlq" --attributes FifoQueue=true
  local dlq_arn
  dlq_arn=$(awslocal sqs get-queue-attributes --queue-url "http://localhost:4566/000000000000/$dlq" \
    --attribute-names QueueArn --query 'Attributes.QueueArn' --output text)
  awslocal sqs create-queue --queue-name "$name.fifo" --attributes \
    "{\"FifoQueue\":\"true\",\"ContentBasedDeduplication\":\"false\",\"VisibilityTimeout\":\"60\",\"RedrivePolicy\":\"{\\\"deadLetterTargetArn\\\":\\\"$dlq_arn\\\",\\\"maxReceiveCount\\\":\\\"3\\\"}\"}"
}

create_fifo service-orders-created
create_fifo service-order-status-changed
