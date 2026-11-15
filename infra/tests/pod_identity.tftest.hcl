mock_provider "aws" {
  mock_data "aws_eks_node_group" {
    defaults = {
      node_role_arn = "arn:aws:iam::123456789012:role/test-node"
    }
  }

  mock_resource "aws_iam_role" {
    defaults = {
      arn = "arn:aws:iam::123456789012:role/test-workload"
    }
  }

  mock_resource "aws_lambda_function" {
    defaults = {
      arn = "arn:aws:lambda:us-east-1:123456789012:function:test"
    }
  }

  mock_resource "aws_cloudwatch_event_rule" {
    defaults = {
      arn = "arn:aws:events:us-east-1:123456789012:rule/test"
    }
  }
}

override_resource {
  target = aws_sqs_queue.main["service_orders_created"]
  values = {
    arn = "arn:aws:sqs:us-east-1:123456789012:input.fifo"
  }
}

override_resource {
  target = aws_sqs_queue.main["service_order_status_changed"]
  values = {
    arn = "arn:aws:sqs:us-east-1:123456789012:output.fifo"
  }
}

override_resource {
  target = aws_sqs_queue.main["service_order_execution"]
  values = {
    arn = "arn:aws:sqs:us-east-1:123456789012:execution.fifo"
  }
}

override_data {
  target = data.archive_file.lambda
  values = {
    output_path         = "build/services-ms-lambda.zip"
    output_base64sha256 = "dGVzdA=="
  }
}

variables {
  db_password = "test-only-password"
  jwt_secret  = "test-only-secret"
}

run "identity_disabled_by_default" {
  command = plan

  assert {
    condition = (
      length(aws_eks_addon.pod_identity_agent) == 0 &&
      length(aws_iam_role.pod_identity) == 0 &&
      length(aws_eks_pod_identity_association.workload) == 0
    )
    error_message = "Pod Identity deve permanecer desativado por padrão."
  }
}

run "identity_has_no_workloads" {
  command = apply

  variables {
    enable_pod_identity = true
  }

  assert {
    condition = (
      length(aws_eks_addon.pod_identity_agent) == 1 &&
      length(aws_iam_role.pod_identity) == 0 &&
      length(aws_eks_pod_identity_association.workload) == 0
    )
    error_message = "Os workers rodam em Lambda: nenhuma role de Pod Identity deve ser criada."
  }
}

run "lambda_workers_scoped_to_their_queues" {
  command = apply

  assert {
    condition = (
      toset(keys(aws_lambda_function.worker)) == toset(["service-orders-consumer", "status-consumer", "outbox-publisher"]) &&
      aws_lambda_function.worker["service-orders-consumer"].handler == "App\\Lambda\\ServiceOrdersCreatedHandler" &&
      aws_lambda_function.worker["status-consumer"].handler == "App\\Lambda\\ServiceOrderStatusChangedHandler" &&
      aws_lambda_function.worker["outbox-publisher"].handler == "App\\Lambda\\PublishOutboxHandler" &&
      alltrue([for fn in aws_lambda_function.worker : fn.timeout <= 60])
    )
    error_message = "Cada worker deve ter sua Lambda, com timeout dentro do visibility timeout das filas (60s)."
  }

  assert {
    condition = (
      aws_lambda_event_source_mapping.sqs["service-orders-consumer"].event_source_arn == aws_sqs_queue.main["service_orders_created"].arn &&
      aws_lambda_event_source_mapping.sqs["status-consumer"].event_source_arn == aws_sqs_queue.main["service_order_status_changed"].arn &&
      !contains(keys(aws_lambda_event_source_mapping.sqs), "outbox-publisher") &&
      alltrue([for m in aws_lambda_event_source_mapping.sqs : contains(m.function_response_types, "ReportBatchItemFailures")])
    )
    error_message = "Os consumers devem ser acionados pelas suas filas e reportar falhas por mensagem."
  }

  assert {
    condition = (
      jsondecode(aws_iam_role_policy.lambda_sqs["service-orders-consumer"].policy).Statement[0].Resource == aws_sqs_queue.main["service_orders_created"].arn &&
      !contains(jsondecode(aws_iam_role_policy.lambda_sqs["service-orders-consumer"].policy).Statement[0].Action, "sqs:SendMessage") &&
      jsondecode(aws_iam_role_policy.lambda_sqs["status-consumer"].policy).Statement[0].Resource == aws_sqs_queue.main["service_order_status_changed"].arn &&
      !contains(jsondecode(aws_iam_role_policy.lambda_sqs["status-consumer"].policy).Statement[0].Action, "sqs:SendMessage") &&
      jsondecode(aws_iam_role_policy.lambda_sqs["outbox-publisher"].policy).Statement[0].Action == ["sqs:SendMessage"] &&
      jsondecode(aws_iam_role_policy.lambda_sqs["outbox-publisher"].policy).Statement[0].Resource == aws_sqs_queue.main["service_order_execution"].arn
    )
    error_message = "As roles das Lambdas devem ter acesso mínimo às suas respectivas filas."
  }

  assert {
    condition     = aws_cloudwatch_event_rule.outbox_publisher.schedule_expression == "rate(1 minute)"
    error_message = "O publisher da outbox deve ser agendado a cada minuto."
  }
}

run "lambda_metrics_disabled_without_license_key" {
  command = plan

  assert {
    condition = alltrue([
      for fn in aws_lambda_function.worker :
      length(fn.layers) == 1 && fn.environment[0].variables["OTEL_METRICS_EXPORTER"] == "none"
    ])
    error_message = "Sem license key do New Relic, as Lambdas não devem carregar a layer do collector."
  }
}

run "lambda_metrics_use_collector_layer" {
  command = plan

  variables {
    new_relic_license_key = "test-only-license"
  }

  assert {
    condition = alltrue([
      for fn in aws_lambda_function.worker :
      length(fn.layers) == 2 &&
      endswith(fn.layers[1], ":layer:opentelemetry-collector-amd64-${var.otel_collector_layer_version}:1") &&
      fn.environment[0].variables["OTEL_METRICS_EXPORTER"] == "otlp" &&
      fn.environment[0].variables["OTEL_EXPORTER_OTLP_ENDPOINT"] == "http://localhost:4318" &&
      fn.environment[0].variables["OPENTELEMETRY_COLLECTOR_CONFIG_URI"] == "/var/task/collector.yaml"
    ])
    error_message = "Com license key, cada Lambda deve exportar OTLP para a extension do collector."
  }
}
