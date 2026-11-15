# Consumers e publisher do Services MS rodando em Lambda (PHP 8.4 via Bref).
#
# O pacote é montado por scripts/build-lambda.sh em build/lambda antes do plan.
# As Lambdas rodam fora da VPC: o RDS do MS é público (laboratório) e o SG "rds"
# libera a 3306; assim o publisher alcança o SQS sem NAT nem VPC endpoint.

locals {
  # Versão da layer php-84 do Bref (vendor/bref/bref/layers.json, bref/bref 2.4.x).
  bref_php_layer_arn = "arn:aws:lambda:${var.aws_region}:534081306603:layer:php-84:${var.bref_php_layer_version}"

  # OTel Collector como Lambda extension: o PHP exporta para localhost:4318 e o
  # collector repassa ao New Relic. As funções usam x86_64 (padrão), daí o amd64.
  otel_enabled             = var.new_relic_license_key != ""
  otel_collector_layer_arn = "arn:aws:lambda:${var.aws_region}:184161586896:layer:opentelemetry-collector-amd64-${var.otel_collector_layer_version}:1"

  otel_environment = local.otel_enabled ? {
    OTEL_METRICS_EXPORTER              = "otlp"
    OTEL_EXPORTER_OTLP_ENDPOINT        = "http://localhost:4318"
    OTEL_SERVICE_NAME                  = "services-ms"
    OPENTELEMETRY_COLLECTOR_CONFIG_URI = "/var/task/collector.yaml"
    NEW_RELIC_OTLP_ENDPOINT            = var.new_relic_otlp_endpoint
    NEW_RELIC_LICENSE_KEY              = var.new_relic_license_key
    } : {
    OTEL_METRICS_EXPORTER = "none"
  }

  lambda_base_environment = {
    APP_NAME                  = "Services MS"
    APP_ENV                   = "production"
    APP_DEBUG                 = "false"
    LOG_CHANNEL               = "stderr"
    BREF_LARAVEL_OMIT_INITLOG = "1"
    DB_CONNECTION             = "mysql"
    DB_HOST                   = aws_db_instance.db.address
    DB_PORT                   = tostring(aws_db_instance.db.port)
    DB_DATABASE               = var.db_name
    DB_USERNAME               = var.db_username
    DB_PASSWORD               = var.db_password
    CACHE_STORE               = "array"
    SESSION_DRIVER            = "array"
    QUEUE_CONNECTION          = "sync"
  }

  sqs_consumer_actions = [
    "sqs:ReceiveMessage",
    "sqs:DeleteMessage",
    "sqs:GetQueueAttributes",
    "sqs:ChangeMessageVisibility",
  ]

  lambda_functions = {
    # Monólito -> MS: criação da O.S. (e eventos legados de aprovação).
    service-orders-consumer = {
      handler   = "App\\Lambda\\ServiceOrdersCreatedHandler"
      timeout   = 30
      queue_key = "service_orders_created"
      actions   = local.sqs_consumer_actions
      env       = {}
    }
    # Aplicação -> MS: ServiceOrderStatusChanged.
    status-consumer = {
      handler   = "App\\Lambda\\ServiceOrderStatusChangedHandler"
      timeout   = 30
      queue_key = "service_order_status_changed"
      actions   = local.sqs_consumer_actions
      env       = {}
    }
    # MS -> aplicação: outbox de início/fim de execução, agendado a cada minuto.
    outbox-publisher = {
      handler   = "App\\Lambda\\PublishOutboxHandler"
      timeout   = 60
      queue_key = "service_order_execution"
      actions   = ["sqs:SendMessage"]
      env = {
        SQS_SERVICE_ORDER_EXECUTION_URL = aws_sqs_queue.main["service_order_execution"].url
      }
    }
  }

  sqs_triggered_functions = {
    for name, fn in local.lambda_functions : name => fn if name != "outbox-publisher"
  }
}

data "archive_file" "lambda" {
  type        = "zip"
  source_dir  = "${path.module}/../build/lambda"
  output_path = "${path.module}/../build/services-ms-lambda.zip"
}

resource "aws_iam_role" "lambda" {
  for_each = local.lambda_functions

  name = "${var.project_name}-${each.key}-lambda"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "lambda.amazonaws.com" }
      Action    = "sts:AssumeRole"
    }]
  })
}

resource "aws_iam_role_policy_attachment" "lambda_basic_execution" {
  for_each = local.lambda_functions

  role       = aws_iam_role.lambda[each.key].name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AWSLambdaBasicExecutionRole"
}

resource "aws_iam_role_policy" "lambda_sqs" {
  for_each = local.lambda_functions

  name = "${var.project_name}-${each.key}-sqs"
  role = aws_iam_role.lambda[each.key].id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = each.value.actions
      Resource = aws_sqs_queue.main[each.value.queue_key].arn
    }]
  })
}

resource "aws_cloudwatch_log_group" "lambda" {
  for_each = local.lambda_functions

  name              = "/aws/lambda/${var.project_name}-${each.key}"
  retention_in_days = 14
}

resource "aws_lambda_function" "worker" {
  for_each = local.lambda_functions

  function_name    = "${var.project_name}-${each.key}"
  role             = aws_iam_role.lambda[each.key].arn
  runtime          = "provided.al2"
  layers           = local.otel_enabled ? [local.bref_php_layer_arn, local.otel_collector_layer_arn] : [local.bref_php_layer_arn]
  handler          = each.value.handler
  filename         = data.archive_file.lambda.output_path
  source_code_hash = data.archive_file.lambda.output_base64sha256
  timeout          = each.value.timeout
  memory_size      = 512

  environment {
    variables = merge(local.lambda_base_environment, local.otel_environment, each.value.env)
  }

  depends_on = [
    aws_cloudwatch_log_group.lambda,
    aws_iam_role_policy_attachment.lambda_basic_execution,
    aws_iam_role_policy.lambda_sqs,
  ]
}

# Consumers: o event source mapping faz o polling e apaga as mensagens processadas.
# Falhas voltam via batchItemFailures e seguem o redrive (3 tentativas -> DLQ).
resource "aws_lambda_event_source_mapping" "sqs" {
  for_each = local.sqs_triggered_functions

  event_source_arn        = aws_sqs_queue.main[each.value.queue_key].arn
  function_name           = aws_lambda_function.worker[each.key].arn
  batch_size              = 10
  function_response_types = ["ReportBatchItemFailures"]

  # A conta tem limite de 10 execuções concorrentes: sem reserved concurrency,
  # o teto fica no mapping. Em FIFO, a ordem por MessageGroupId é preservada.
  scaling_config {
    maximum_concurrency = 2
  }
}

# Publisher: o EventBridge invoca a cada minuto; a invocação publica em ciclos
# até perto do timeout. Um lock no MySQL impede publishers concorrentes.
resource "aws_cloudwatch_event_rule" "outbox_publisher" {
  name                = "${var.project_name}-outbox-publisher"
  description         = "Publica a outbox do Services MS na fila de execucao"
  schedule_expression = "rate(1 minute)"
}

resource "aws_cloudwatch_event_target" "outbox_publisher" {
  rule = aws_cloudwatch_event_rule.outbox_publisher.name
  arn  = aws_lambda_function.worker["outbox-publisher"].arn

  retry_policy {
    maximum_retry_attempts       = 0
    maximum_event_age_in_seconds = 60
  }
}

resource "aws_lambda_permission" "outbox_publisher_schedule" {
  statement_id  = "AllowEventBridgeInvoke"
  action        = "lambda:InvokeFunction"
  function_name = aws_lambda_function.worker["outbox-publisher"].function_name
  principal     = "events.amazonaws.com"
  source_arn    = aws_cloudwatch_event_rule.outbox_publisher.arn
}

# A próxima execução agendada já tenta de novo; retries assíncronos só sobreporiam invocações.
resource "aws_lambda_function_event_invoke_config" "outbox_publisher" {
  function_name                = aws_lambda_function.worker["outbox-publisher"].function_name
  maximum_retry_attempts       = 0
  maximum_event_age_in_seconds = 60
}
