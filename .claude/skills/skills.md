# Skills — tech-challenge-services-ms

Playbooks para o Claude Code neste repositório. O contexto completo do projeto está em `CLAUDE.md`.

---

## terraform-workflow

Fluxo padrão para qualquer alteração em `infra/`.

**Quando usar:** criar, alterar ou destruir filas, Lambdas, RDS ou Pod Identity.

**Pré-requisito:** credenciais AWS no terminal (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN` se forem temporárias, `AWS_DEFAULT_REGION=us-east-1`).

```bash
scripts/build-lambda.sh                       # obrigatório antes do plan (archive_file lê build/lambda)
cd infra
terraform fmt *.tf                            # o CI roda fmt -check
terraform init -reconfigure                   # backend S3 já está em terraform.tf
terraform validate
terraform test                                # tests/pod_identity.tftest.hcl
TF_VAR_db_password=... TF_VAR_jwt_secret=... \
  terraform plan -var=enable_pod_identity=true -out=tfplan
terraform apply tfplan                        # só depois de revisar o plano
terraform output
```

**Regras:**
- Sempre `-var=enable_pod_identity=true` (igual ao CI), senão o plano remove as associações.
- Leia o plano procurando `aws_db_instance.db` com `must be replaced`: o banco **não é migrado**. Pare e avise o usuário.
- O merge em `main` aplica sozinho (`-auto-approve`). Trate o PR como o momento da revisão.

---

## add-event-consumer

Adiciona um novo tipo de evento consumido de uma fila existente.

**Checklist:**
- [ ] Criar `app/Application/ServiceOrder/Process{Evento}.php` com `public const TYPE` e `handle(array $payload): bool`
- [ ] Validar o payload e lançar `InvalidArgumentException` (vai para a DLQ após 3 recebimentos)
- [ ] Numa única `DB::transaction`: insert em `processed_messages` (`event_id`, `type`) + o efeito. Capturar `UniqueConstraintViolationException` → `return false`
- [ ] Se depende de outro evento que pode não ter chegado (ex.: O.S. ainda não importada), lançar `ModelNotFoundException` para o SQS reentregar
- [ ] Registrar a métrica **depois** da transação
- [ ] Incluir o tipo em `eventTypes()` e no `match` do handler da fila (`app/Lambda/*Handler.php`)
- [ ] Teste em `tests/Feature/` (idempotência: processar duas vezes) e em `LambdaHandlersTest`
- [ ] Documentar o evento no README ("Arquitetura" / "Fluxo de status")

---

## add-lambda

Adiciona uma nova Lambda (nova fila ou novo agendamento).

**Checklist:**
- [ ] Handler em `app/Lambda/`: estender `FifoSqsHandler` (SQS FIFO) ou implementar `Bref\Event\Handler`
- [ ] Em handlers que não estendem `FifoSqsHandler`, chamar `$this->metrics->flush()` num `finally` (o processo da Lambda é reaproveitado)
- [ ] Adicionar a entrada em `local.lambda_functions` (`infra/lambda.tf`): `handler`, `timeout`, `queue_key`, `actions` (mínimo necessário), `env`
- [ ] Nova fila? Adicionar em `local.fifo_queues` (`infra/sqs.tf`) — DLQ e redrive vêm juntos
- [ ] Gatilho SQS entra sozinho em `sqs_triggered_functions`; agendamento precisa de `aws_cloudwatch_event_rule` + target + `aws_lambda_permission` (copiar o do publisher)
- [ ] **Não** usar reserved concurrency (limite de 10 na conta); usar `scaling_config.maximum_concurrency`
- [ ] Atualizar a tabela de Lambdas no README e o diagrama `docs/architecture.png` (skill `update-diagram`)
- [ ] Rodar `terraform-workflow`

---

## add-api-route

**Checklist:**
- [ ] Rota em `routes/api.php` dentro do grupo `middleware('jwt')` (não há prefixo `/api`)
- [ ] Lógica num caso de uso em `app/Application/`, com `lockForUpdate` se alterar a O.S.
- [ ] Regra de negócio → `DomainException`; o controller converte em 422 via `guard()`
- [ ] Evento para a aplicação? Gravar na `outbox` na mesma transação e adicionar o tipo em `OutboxPublisher::SUPPORTED_TYPES` (senão o grupo da O.S. trava)
- [ ] Teste em `tests/Feature/ServiceOrderExecutionApiTest.php`
- [ ] Request em `bruno/ServiceOrderExecution/` e linha na tabela "API" do README

---

## add-metric

**Checklist:**
- [ ] Criar o instrumento no construtor de `app/Application/Telemetry/Metrics.php` (nome OTel com pontos, unidade, descrição em PT)
- [ ] Método público que recebe só valores de baixa cardinalidade. **Nunca** IDs de O.S./cliente/veículo/usuário
- [ ] Chamar fora da transação (após o commit)
- [ ] Cobrir em `tests/Feature/MetricsTest.php` (usa `InMemoryExporter` com temporalidade delta)
- [ ] Adicionar na tabela de métricas do README (seção "Monitoramento")

---

## aws-ops

```bash
aws sts get-caller-identity
cd infra && terraform output                      # filas, DLQs, RDS, nomes das Lambdas

# Lambdas
aws lambda list-functions --query "Functions[?starts_with(FunctionName,'services-ms-')].FunctionName"
aws logs tail /aws/lambda/services-ms-status-consumer --follow
aws logs tail /aws/lambda/services-ms-outbox-publisher --since 30m
aws lambda get-function-configuration --function-name services-ms-status-consumer --query "{layers:Layers[].Arn,otel:Environment.Variables.OTEL_METRICS_EXPORTER}"

# Event source mappings (Enabled? último resultado?)
aws lambda list-event-source-mappings --function-name services-ms-service-orders-consumer

# Filas
aws sqs get-queue-attributes --queue-url <url> --attribute-names ApproximateNumberOfMessages ApproximateNumberOfMessagesNotVisible

# DLQ
aws sqs receive-message --queue-url <dlq_url> --max-number-of-messages 10
aws sqs start-message-move-task --source-arn <dlq_arn>      # redrive para a fila de origem

# EKS
aws eks update-kubeconfig --name main --region us-east-1
kubectl get pods,svc,hpa,jobs -n services-ms
kubectl logs deploy/services-ms-web -n services-ms --tail=100
kubectl logs deploy/otel-collector -n services-ms --tail=50
```

**Não** imprima Secrets do Kubernetes nem variáveis de ambiente das Lambdas por completo (contêm `DB_PASSWORD` e a license key).

---

## test-full-flow

Valida o fluxo ponta a ponta sem a aplicação, publicando direto nas filas.
Use IDs novos (`uuidgen | tr A-Z a-z`) e, para as filas FIFO, `--message-group-id` = id da O.S. e
`--message-deduplication-id` = `event_id`.

```bash
OS=$(uuidgen | tr A-Z a-z); SVC=$(uuidgen | tr A-Z a-z)
CREATED=$(cd infra && terraform output -raw sqs_service_orders_created_url)
STATUS=$(cd infra && terraform output -raw sqs_service_order_status_changed_url)

# 1. Criação
EV=$(uuidgen | tr A-Z a-z)
aws sqs send-message --queue-url "$CREATED" --message-group-id "$OS" --message-deduplication-id "$EV" --message-body "{
  \"event_id\":\"$EV\",\"type\":\"ServiceOrderCreated\",\"sequence\":1,
  \"service_order\":{\"id\":\"$OS\",\"customer_id\":\"$(uuidgen | tr A-Z a-z)\",\"vehicle_id\":\"$(uuidgen | tr A-Z a-z)\",\"services_total\":150,\"status\":\"recebida\"},
  \"services\":[{\"id\":\"$SVC\",\"service_id\":\"$(uuidgen | tr A-Z a-z)\",\"name\":\"Troca de óleo\",\"quantity\":1,\"price\":150}]}"

# 2. Status em_execucao (sequence maior que o anterior)
EV=$(uuidgen | tr A-Z a-z)
aws sqs send-message --queue-url "$STATUS" --message-group-id "$OS" --message-deduplication-id "$EV" --message-body "{
  \"event_id\":\"$EV\",\"type\":\"ServiceOrderStatusChanged\",\"version\":1,\"service_order_id\":\"$OS\",\"sequence\":2,\"status\":\"em_execucao\"}"

# 3. API (token HS256 com o JWT_SECRET da aplicação, claim sub obrigatório)
TOKEN=$(php -r 'require "vendor/autoload.php"; echo Firebase\JWT\JWT::encode(["sub"=>"1","exp"=>time()+3600], getenv("JWT_SECRET"), "HS256");')
HOST=$(kubectl get svc services-ms-web -n services-ms -o jsonpath='{.status.loadBalancer.ingress[0].hostname}')
curl -s "http://$HOST:8081/service-orders/$OS" -H "Authorization: Bearer $TOKEN"
curl -s -X POST "http://$HOST:8081/service-orders/$OS/services/$SVC/start"  -H "Authorization: Bearer $TOKEN"
curl -s -X POST "http://$HOST:8081/service-orders/$OS/services/$SVC/finish" -H "Authorization: Bearer $TOKEN"

# 4. Em até ~1 min o publisher envia ServiceStarted/ServiceFinished (um por serviço) e ExecutionStarted/ExecutionFinished
#    Na aplicação: GET /api/service-order/$OS mostra services[].started_at / finished_at
aws logs tail /aws/lambda/services-ms-outbox-publisher --since 5m
```

**Cenários negativos:**
- `start` antes do passo 2 → 422 (status não sincronizado / não está em `em_execucao`)
- Reenviar a mesma mensagem (mesmo `event_id`) → `Skipped` no log, nada muda
- `sequence` menor que o atual → ignorado
- Corpo inválido → `Invalid SQS message` no log → DLQ após 3 recebimentos
- Sem token / token com outro secret → 401

**Atenção:** a aplicação real também consome `service-order-execution.fifo`. Em ambiente compartilhado,
O.S. inventadas geram eventos que ela vai descartar (a O.S. não existe lá). Avise o usuário antes de rodar.

---

## deploy

```bash
gh workflow run ci-cd.yml --ref main
gh run watch
gh run view --log-failed          # se falhar
```

**Pós-deploy:**
- `kubectl get pods,jobs -n services-ms` — `services-ms-migrate` Complete, `services-ms-web` e `otel-collector` Running
- `curl http://$HOST:8081/up` → 200
- `aws lambda list-event-source-mappings ...` → `State: Enabled`
- DLQs vazias (`ApproximateNumberOfMessages = 0`). Se encheram logo após uma migration nova, fazer redrive.
- Se o RDS foi recriado: atualizar o secret `DB_HOST` (`terraform output -raw rds_endpoint`) e rodar o deploy de novo.

---

## newrelic-monitoring

**Quando usar:** validar métricas ou investigar algo pelo New Relic.

1. Confirmar que chegam: `FROM Metric SELECT count(*) WHERE service.name = 'services-ms' SINCE 30 minutes ago FACET metricName`
   (o usuário roda no *Query your data* e manda print — não há User key aqui).
2. Faltam métricas das Lambdas → conferir a layer do collector e `OTEL_METRICS_EXPORTER=otlp` (ver `aws-ops`).
3. Faltam métricas da API → `kubectl logs deploy/otel-collector -n services-ms` e o Secret `otel-collector-secret`.
Conta `8497993`. Tabela de métricas e consultas prontas no README (seção "Monitoramento").

---

## update-diagram

`docs/architecture.png` é o único arquivo em `docs/` e a fonte dele não é versionada.
Ao mudar o fluxo (filas, Lambdas, API), refaça o PNG mantendo o mesmo estilo (tema escuro, caixas por cor:
λ/API em índigo, SQS em ciano, RDS em laranja, DLQ em vermelho, New Relic em roxo) e confira o resultado abrindo a imagem.
