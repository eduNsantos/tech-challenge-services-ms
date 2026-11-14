locals {
  fifo_queues = {
    service_orders_created       = "service-orders-created"
    service_order_status_changed = "service-order-status-changed"
  }

  services_ms_sqs_actions = [
    "sqs:ChangeMessageVisibility",
    "sqs:DeleteMessage",
    "sqs:GetQueueAttributes",
    "sqs:ReceiveMessage",
    "sqs:SendMessage",
  ]
}

data "aws_eks_node_group" "main" {
  cluster_name    = "main"
  node_group_name = "main-ng"
}

resource "aws_sqs_queue" "dlq" {
  for_each = local.fifo_queues

  name                      = "${var.project_name}-${each.value}-dlq.fifo"
  fifo_queue                = true
  message_retention_seconds = 1209600
  sqs_managed_sse_enabled   = true
}

resource "aws_sqs_queue" "main" {
  for_each = local.fifo_queues

  name                        = "${var.project_name}-${each.value}.fifo"
  fifo_queue                  = true
  content_based_deduplication = false
  visibility_timeout_seconds  = 60
  receive_wait_time_seconds   = 20
  sqs_managed_sse_enabled     = true

  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.dlq[each.key].arn
    maxReceiveCount     = 3
  })
}

resource "aws_sqs_queue_redrive_allow_policy" "dlq" {
  for_each = local.fifo_queues

  queue_url = aws_sqs_queue.dlq[each.key].id

  redrive_allow_policy = jsonencode({
    redrivePermission = "byQueue"
    sourceQueueArns   = [aws_sqs_queue.main[each.key].arn]
  })
}

resource "aws_sqs_queue_policy" "eks_node_role" {
  for_each = local.fifo_queues

  queue_url = aws_sqs_queue.main[each.key].id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Sid    = "AllowEKSNodeRoleServicesMsAccess"
      Effect = "Allow"
      Principal = {
        AWS = data.aws_eks_node_group.main.node_role_arn
      }
      Action   = local.services_ms_sqs_actions
      Resource = aws_sqs_queue.main[each.key].arn
    }]
  })
}
