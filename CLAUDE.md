# CLAUDE.md — tech-challenge-services-ms

## O que é este projeto

Microsserviço de **execução dos serviços da O.S.** (Tech Challenge FIAP POS): início/fim de cada serviço (cada um vira evento para a aplicação) e aviso de fim de execução.
Laravel 12 / PHP 8.4. A **O.S. e o status pertencem ao `tech-challenge-application`**; o MS guarda uma cópia somente leitura do status.
Diagrama: `docs/architecture.png`. Detalhes de operação, métricas e corte no `README.md`.

## Arquitetura

```
application ─outbox─> SQS service-orders-created.fifo ──> λ services-ms-service-orders-consumer ─┐
            ─outbox─> SQS service-order-status-changed.fifo ──> λ services-ms-status-consumer ────┼─> RDS MySQL services_ms
Mecânico ─JWT─> LB :8081 ─> API web (EKS, services-ms-web) ─ start/finish + outbox ──────────────┤
EventBridge rate(1 min) ─> λ services-ms-outbox-publisher ─ lê outbox ───────────────────────────┘
                              └─> SQS service-order-execution.fifo ─> application (λ postech-execution-consumer)
EventBridge rate(15 min) ─> λ services-ms-dlq-redriver ─ DLQs das 3 filas ─> fila de origem (nova tentativa)
```

- Filas FIFO com `MessageGroupId` = id da O.S. e `MessageDeduplicationId` = `event_id`; DLQ `*-dlq.fifo` com `maxReceiveCount = 3`.
- `service-order-status-changed` **inverteu de sentido** (antes MS → app; agora app → MS). Ver "Corte" no README.
- Eventos de saída (mesma fila, grupo = id da O.S.): `ServiceOrderServiceStarted`/`ServiceOrderServiceFinished` em todo start/finish (com `service_order_service_id`, `user_id`, `started_at`/`finished_at`) e `ServiceOrderExecutionStarted`/`ServiceOrderExecutionFinished` no 1º início e no último fim. Tipo novo precisa entrar em `OutboxPublisher::SUPPORTED_TYPES` e ser aceito pelo consumer da aplicação **antes** do deploy do MS.
- Falha ao processar (qualquer lado) → 3 recebimentos → DLQ → `dlq-redriver` devolve à fila de origem até `SQS_REDRIVE_MAX_ATTEMPTS` (atributo `RedriveAttempt`). Esgotou: fica na DLQ, segura o grupo FIFO e gera log/métrica `exhausted`.
- Status aplicado só se `sequence > status_sequence`. Início de serviço só com O.S. em `em_execucao` e `status_sequence` preenchido.

### Status (`App\Domain\ServiceOrder\ServiceOrderStatus`)
```
recebida → em_diagnostico ⇄ aguardando_aprovacao → em_execucao → finalizada → entregue
recebida → aguardando_aprovacao
```

## Stack

| Camada | Tecnologia |
|---|---|
| Linguagem | PHP 8.4, Laravel 12 |
| Workers | AWS Lambda `provided.al2` + layer Bref `php-84` (`bref/laravel-bridge`) |
| API | EKS cluster `main`, namespace `services-ms`, Deployment `services-ms-web` (HPA 1–4), Service LoadBalancer `:8081 → :8000` |
| Banco | RDS MySQL 8.4 `services-ms-mysql` (`db.t3.micro`, público no lab) |
| Filas | SQS FIFO ×3 + DLQs (nova tentativa automática pela `dlq-redriver`) |
| Infra | Terraform ≥ 1.10 (CI 1.10.5), backend S3 `techchallenge-tfstate-477478162709-v2`, key `services-ms/terraform.tfstate` |
| Imagem | `ghcr.io/edunsantos/tech-challenge-services-ms:<sha>` |
| Observabilidade | OpenTelemetry (métricas OTLP delta + logs da jornada da O.S.) → collector → New Relic |

## Estrutura de arquivos

```
app/
  Domain/ServiceOrder/                 # entidades + enum de status (sem Laravel)
  Application/
    ServiceOrder/ProcessServiceOrderCreated.php        # consumer: importa O.S. + serviços
    ServiceOrder/ProcessServiceOrderStatusChanged.php  # consumer: status com sequence
    ServiceOrder/ProcessServiceOrderApprovalChanged.php# legado — só O.S. sem status_sequence
    Execution/ServiceOrderExecution.php                # start/finish + grava outbox
    Outbox/OutboxPublisher.php                         # outbox → SQS (GET_LOCK, bloqueio por grupo)
    Messaging/DeadLetterRedriver.php                   # DLQ → fila de origem (nova tentativa com limite)
    Telemetry/Metrics.php                              # instrumentos OTel
    Telemetry/ServiceOrderJourney.php                  # logs OTel da jornada da O.S. (com service_order_id)
  Lambda/FifoSqsHandler.php            # base dos consumers (batchItemFailures, para na 1ª falha)
  Lambda/*Handler.php                  # 4 Lambdas (2 consumers, publisher, dlq-redriver)
  Http/Controllers/ServiceOrderExecutionController.php
  Http/Middleware/VerifyJwt.php        # HS256, claim sub → request attribute user_id
  Http/Middleware/RecordHttpMetrics.php
  Infrastructure/                      # repositórios Eloquent, MeterProviderFactory, LoggerProviderFactory
database/migrations/                   # service_orders, service_order_services, processed_messages, outbox
routes/api.php                         # apiPrefix '' → rotas sem /api
infra/                                 # sqs.tf, rds.tf, vpc.tf, lambda.tf, pod-identity.tf, tests/
k8s/                                   # 00-namespace, 01-config, 02-app (web, migrate, swagger-ui), 03-observability
openapi.yaml                           # contrato da API; CI gera o ConfigMap services-ms-openapi a partir dele
otel/lambda-collector.yaml             # config do collector da layer
scripts/build-lambda.sh                # monta build/lambda (Terraform compacta)
docs/architecture.png                  # único arquivo de docs; ao mudar o fluxo, avise que o diagrama precisa ser atualizado
```

## Constraints críticos

### IAM
Diferente do payment-service (AWS Academy/LabRole), **este repo cria roles IAM** (`aws_iam_role.lambda`, uma por Lambda, com
acesso mínimo à fila dela). Pod Identity é opcional (`enable_pod_identity`); o CI passa `-var=enable_pod_identity=true` para
não remover associações. Mantenha esse `-var` em plans manuais.

### Terraform e RDS
- O CI faz `terraform apply -auto-approve` em todo push para `main`. **Nunca** mude `infra/rds.tf` sem revisar se o plano substitui o banco (os dados não são migrados).
- `scripts/build-lambda.sh` precisa rodar antes do `plan` (o `archive_file` lê `build/lambda`).
- `terraform fmt -check` roda no PR — formate os `.tf` antes do commit.

### Ordem e idempotência (não quebrar)
- Todo efeito de consumer vai na **mesma transação** do insert em `processed_messages` (PK `event_id`). Duplicata → `UniqueConstraintViolationException` → `return false`.
- Eventos de saída são gravados na tabela `outbox` na mesma transação da mudança de estado. Nunca chame o SQS direto da API.
- Em `FifoSqsHandler`, depois da primeira falha **todas** as mensagens seguintes do lote vão para `batchItemFailures`.
- `OutboxPublisher` bloqueia o grupo da O.S. ao falhar um evento; só tipos de `SUPPORTED_TYPES` são publicados.
- Payload inválido → `InvalidArgumentException` (vai para a DLQ após 3). O.S. ainda não importada → `ModelNotFoundException` (retry).

### Concorrência da conta
Limite de 10 execuções Lambda simultâneas na conta: **sem reserved concurrency**; o teto é `maximum_concurrency = 2` em cada event source mapping.

## CI/CD (GitHub Actions — `.github/workflows/ci-cd.yml`)

- PR → `test` (PHPUnit, PHP 8.4) + `terraform` (fmt/validate).
- Push/dispatch em `main` → `terraform_apply` (build Lambda, plan, remove workers legados do EKS, apply) → `build` (GHCR) → `deploy` (ConfigMap/Secrets, otel-collector, Job de migrations, rollout).
- Migrations rodam **depois** do apply (no job `deploy`). Mudança de schema que as Lambdas precisam pode causar falhas temporárias → DLQ.

Secrets: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `APP_KEY`, `JWT_SECRET`,
`GHCR_USERNAME`, `GHCR_TOKEN`, `NEW_RELIC_LICENSE_KEY`. `JWT_SECRET` precisa ser igual ao da aplicação.

## Monitoramento — New Relic via OpenTelemetry

- **Sem agente New Relic.** Métricas via `App\Application\Telemetry\Metrics` (SDK OTel) → OTLP/HTTP delta.
- API: `otel-collector` no namespace (`k8s/03-observability`). Lambdas: layer `opentelemetry-collector-amd64-<versão>` + `otel/lambda-collector.yaml`.
- Liga só com `OTEL_METRICS_EXPORTER=otlp`. Dev/testes usam `none`. Lambdas ficam sem layer se `new_relic_license_key` estiver vazia.
- Exportação: API no `terminating`; Lambdas chamam `Metrics::flush()` **e** `ServiceOrderJourney::flush()` no `finally` do handler — **todo handler novo precisa disso**.
- Registre métricas **depois do commit** da transação (rollback não pode virar métrica).
- **Nunca** use IDs (O.S., cliente, veículo, usuário) como atributo de métrica: cardinalidade + dados pessoais. Rotas usam o template (`$request->route()->uri()`).
- O id da O.S. vai nos logs da jornada (`ServiceOrderJourney::record($event, $orderId, $attrs)`, `OTEL_LOGS_EXPORTER=otlp`), que viram `Log` no New Relic com `service_order_id` — o mesmo atributo da aplicação e do payment (dashboard "Jornada da O.S." no repo da aplicação). Registre depois do commit; sem dados pessoais. Passos novos: documente na tabela do README e teste em `tests/Feature/ServiceOrderJourneyTest.php`.
- Atributos de tipo de evento vindos da mensagem passam por `eventTypes()` (desconhecido → `unknown`).
- Conta New Relic `8497993`. Validar por NRQL: `FROM Metric SELECT count(*) WHERE service.name = 'services-ms' FACET metricName`.
- Não há como consultar o New Relic daqui (a license key só envia). O usuário roda a NRQL e manda print.

## Padrões do projeto

- Camadas: `Domain` (puro) → `Application` (casos de uso, transações) → `Infrastructure`/`Http`/`Lambda` (adaptadores).
- Regra de negócio violada → `DomainException` → controller responde 422 e registra `service_order.execution.rejected`.
- Lambdas = Bref handlers; estendem `FifoSqsHandler` (SQS) ou implementam `Bref\Event\Handler`. Dependências pelo container do Laravel.
- `SqsClient` singleton (`AppServiceProvider`), com `SQS_ENDPOINT` opcional para LocalStack.
- Testes: `php artisan test`; Lambdas cobertas em `tests/Feature/LambdaHandlersTest.php`, métricas em `tests/Feature/MetricsTest.php` (exporter em memória).
- Textos de docs e comentários em português; mensagens de exceção em inglês.
