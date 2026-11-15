# Services MS

Microsserviço de execução dos serviços da O.S. (início e fim de cada serviço). O status da O.S. pertence à aplicação; o MS mantém uma cópia somente leitura.

- Entrada: fila SQS FIFO `service-orders-created` (Lambda `services-ms-service-orders-consumer`), com a criação da O.S.
- Entrada: fila SQS FIFO `service-order-status-changed` (Lambda `services-ms-status-consumer`), com cada `ServiceOrderStatusChanged` publicado pela aplicação.
- Saída: fila SQS FIFO `service-order-execution`, via outbox (Lambda `services-ms-outbox-publisher`), com `ServiceOrderExecutionStarted` e `ServiceOrderExecutionFinished`.
- API (JWT HS256, mesmo `JWT_SECRET` do monólito): `GET /service-orders/{id}`, `POST /service-orders/{id}/services/{sid}/start|finish`. Não há rota para alterar o status.
- O workflow deste repositório publica a imagem e implanta a API pelos manifests em `k8s/`. Os consumers e o publisher são Lambdas criadas pelo Terraform em `infra/`.

### Lambdas (consumers e publisher)

Os workers rodam em Lambda com PHP 8.4 via [Bref](https://bref.sh) (`bref/laravel-bridge`), reaproveitando os casos de uso de `app/Application`. Os handlers ficam em `app/Lambda`:

| Lambda | Handler | Gatilho |
|---|---|---|
| `services-ms-service-orders-consumer` | `App\Lambda\ServiceOrdersCreatedHandler` | Event source mapping da fila `service-orders-created` |
| `services-ms-status-consumer` | `App\Lambda\ServiceOrderStatusChangedHandler` | Event source mapping da fila `service-order-status-changed` |
| `services-ms-outbox-publisher` | `App\Lambda\PublishOutboxHandler` | EventBridge `rate(1 minute)` |

- Consumers: as mensagens com falha (inválidas ou erros transitórios) voltam por `batchItemFailures`. Após 3 recebimentos, o redrive manda a mensagem para a DLQ. Em filas FIFO, o processamento para na primeira falha do lote e devolve também as mensagens seguintes, para não quebrar a ordem.
- Publisher: cada invocação publica em ciclos de 2 s até 10 s antes do timeout (60 s). Um `GET_LOCK` no MySQL garante um único publisher, mesmo com invocações sobrepostas.
- Concorrência: a conta tem limite de 10 execuções simultâneas, então não há reserved concurrency. Cada event source mapping tem `maximum_concurrency = 2`.
- Rede: as Lambdas rodam fora da VPC e acessam o RDS público do laboratório, cujo SG `rds` libera a 3306. Assim alcançam o SQS sem NAT nem VPC endpoint.
- Pacote: `scripts/build-lambda.sh` monta `build/lambda` com `composer install --no-dev`, e o Terraform compacta esse diretório. O CI roda o script antes do `terraform plan`. Para aplicar localmente, rode o script antes (exige PHP 8.4 e Composer). O `composer.json` mantém só o serviço SQS do `aws-sdk-php` para reduzir o pacote.
- Layer: `arn:aws:lambda:us-east-1:534081306603:layer:php-84:<versão>`, definida em `var.bref_php_layer_version`. Ao atualizar o `bref/bref`, use a versão de `vendor/bref/bref/layers.json`.

### Fluxo de status

1. A aplicação grava o status e um `ServiceOrderStatusChanged` na outbox, na mesma transação. O campo `sequence` é o id da linha na outbox da aplicação. Esse id cresce a cada evento da O.S.
2. O MS aplica o evento somente se `sequence` for maior que o `status_sequence` salvo. Eventos repetidos ou antigos são ignorados.
3. Um serviço só pode ser iniciado com a O.S. em `em_execucao`. O primeiro início gera `ServiceOrderExecutionStarted`. A conclusão do último serviço gera `ServiceOrderExecutionFinished`. Os dois eventos carregam o `status_sequence` do momento.
4. A aplicação ignora `ServiceOrderExecutionStarted`, pois a aprovação já leva a O.S. para `em_execucao`. Ela aplica `ServiceOrderExecutionFinished` e muda para `finalizada` somente se a O.S. ainda estiver em `em_execucao` e se `status_sequence` for o último evento de status dela. Se a aplicação mudou o status depois disso, o evento é descartado. A mudança para `finalizada` volta ao MS como um novo `ServiceOrderStatusChanged`.

O.S. sem `status_sequence` (importadas antes desta mudança) recusam início/fim com 422 até receberem um evento de status. Para isso, rode `services-ms:sync-service-order-status` na aplicação (ver "Corte" abaixo). Os eventos legados `ServiceOrderApprovalChanged` ainda são aceitos na fila de criação, mas só alteram O.S. que ainda não têm `status_sequence`.

### Corte (uma única vez)

A fila `service-order-status-changed` inverteu de sentido: antes ia do MS para a aplicação e agora vai da aplicação para o MS. Ordem recomendada:

1. Aplique o Terraform em `infra/` para criar `service-order-execution.fifo` e sua DLQ. Cadastre o secret `SQS_SERVICE_ORDER_EXECUTION_URL` neste repositório.
2. Com a aplicação ainda na versão antiga, pare o outbox antigo do MS (`kubectl scale deployment services-ms-outbox -n services-ms --replicas=0`). Espere a fila `service-order-status-changed` esvaziar, ou seja, o consumidor antigo da aplicação processar o que restou.
3. Implante o MS. O workflow remove os workers antigos do EKS (`services-ms-consumer`, `services-ms-outbox`, `services-ms-status-consumer`) antes do `terraform apply`, que cria as Lambdas. A migration `discard_legacy_status_outbox_events` marca como publicados os `ServiceOrderStatusChanged` antigos ainda pendentes na outbox do MS. Sem isso, o grupo da O.S. ficaria bloqueado no publisher. Atenção: o workflow roda as migrations no job de deploy, depois do `terraform apply`. Até elas terminarem, as Lambdas podem falhar por falta da coluna `status_sequence`, e as mensagens com 3 falhas vão para a DLQ. No corte, rode as migrations antes do apply ou faça o redrive da DLQ depois.
4. Implante a aplicação pelo repositório Kubernetes. O workflow remove `postech-status-consumer` e os workers antigos da aplicação antes de aplicar a nova imagem. No mesmo workflow, depois das migrations, o job `lambdas` cria as Lambdas `postech-outbox-publisher` e `postech-execution-consumer` (`tech-challenge-kubernetes/app-lambda`). Confirme que `SERVICES_MS_ENABLED=true`, senão a aplicação não publica eventos.
5. Na aplicação, rode `php artisan services-ms:backfill-service-orders` para O.S. ainda não importadas e depois `php artisan services-ms:sync-service-order-status`.
6. Verifique as DLQs. Mensagens antigas no formato anterior, sem `sequence`, que chegarem ao novo consumidor do MS vão para a DLQ após 3 tentativas e podem ser descartadas.

## Desenvolvimento local com Docker

Copie `.env.example` para `.env` se ainda não existir e defina nele uma senha
local não vazia para `SERVICES_MS_DB_PASSWORD`. Não compartilhe o arquivo `.env`.
Depois execute:

```bash
docker compose build
docker compose run --rm php composer install
# Somente se APP_KEY ainda não estiver configurada:
docker compose run --rm php php artisan key:generate
docker compose run --rm php php artisan config:clear
docker compose run --rm php php artisan migrate
docker compose up -d
```

A API fica em `localhost:8000`, o MySQL em `127.0.0.1:3307` e o LocalStack em
`localhost:4566`. No container, a aplicação usa o banco `services_ms` em `db`
e as filas do LocalStack. A senha é aplicada na primeira criação do volume
MySQL; alterá-la no `.env` não atualiza um banco já inicializado. O Compose
inicia somente a API; os consumers e o publisher existem apenas como Lambdas
(`app/Lambda`) e são cobertos por `tests/Feature/LambdaHandlersTest.php`.

## Infraestrutura

O Terraform em `infra/` consulta a VPC `main` e as subnets `sub_a`/`sub_b` do
repositório Kubernetes com data sources. O RDS do MS usa essa VPC; a configuração
habilita acesso público e permite MySQL na porta 3306
a partir de qualquer endereço IPv4, além do CIDR da VPC. Essa exposição foi
solicitada apenas para o laboratório e não deve ser usada em produção. Restrinja
os endereços autorizados e rotacione credenciais comprometidas antes de expor
o banco. A configuração ainda precisa ser aplicada após revisão do plano.

O RDS atual está na VPC antiga. A próxima aplicação do Terraform substituirá o
banco e removerá os recursos de rede exclusivos do MS; os dados não são
migrados para o novo RDS. Não aplique antes de revisar o plano e confirmar que
o conteúdo do banco antigo pode ser descartado. Após a substituição, atualize o
GitHub Actions Secret `DB_HOST` com o endpoint novo e confirme que
`DB_USERNAME`/`DB_PASSWORD` correspondem ao RDS criado antes do deploy.

O workflow atual executa `terraform apply` automaticamente em pushes para
`main` e execuções manuais nessa branch. Não o dispare antes de reconciliar o
state e revisar e autorizar o plano, incluindo os efeitos no RDS.

## Deploy no EKS

O workflow `.github/workflows/ci-cd.yml` usa GitHub Actions Secrets para o
deploy. Configure `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` e
`AWS_SESSION_TOKEN` para autenticar o runner no EKS; esses valores são usados
somente pelo runner e não devem ser incluídos nos Secrets dos Pods. Para a
API, o workflow usa `DB_HOST`, `APP_KEY`, `DB_USERNAME`, `DB_PASSWORD` e
`JWT_SECRET`. As Lambdas recebem a configuração do banco e da fila pelo Terraform. `GHCR_USERNAME` e `GHCR_TOKEN` são necessários
para pull da imagem privada.

Cada Lambda tem sua própria role IAM com acesso mínimo: o consumer só recebe da
fila de criação, o status consumer só recebe da fila de status e o publisher só
envia à fila de execução. Os workers da aplicação também viraram Lambdas
(`tech-challenge-kubernetes/app-lambda`). Com isso, nenhum workload do EKS
usa Pod Identity: `pod_identity_workloads` está vazio e o Terraform só cria o
agente quando `enable_pod_identity = true`. O manifesto dessa conta fica no repositório Kubernetes, junto
ao deployment do consumer. A configuração vem desativada por padrão
(`enable_pod_identity = false`) no Terraform; o CI passa explicitamente
`enable_pod_identity = true` no plano para preservar as associações. Essa
configuração local do workflow só terá efeito após ser publicada.

Antes de habilitar, um administrador deve conceder ao operador as permissões
para criar roles/policies e fazer `iam:PassRole`. O agente também exige
`eks-auth:AssumeRoleForPodIdentity` na role dos nodes e acesso à API EKS Auth.
Na verificação do laboratório houve negação explícita dessa ação para a role
dos nodes; uma permissão adicional não supera uma negação explícita. Resolva
esse bloqueio antes do apply. Verifique também se o addon já existe: caso seja
gerenciado fora deste Terraform, concilie sua propriedade antes de habilitar.

Em execuções manuais do Terraform, mantenha `enable_pod_identity = true` para
evitar a remoção das associações. Revise o plano completo e obtenha autorização
antes de aplicar; o CI continua executando apply automaticamente em `main`.
A política temporária existente
das filas para a role dos nodes permanece até uma remoção planejada e
autorizada; enquanto estiver ativa, não há isolamento completo dos demais
pods. Após a associação estar pronta, aplique as ServiceAccounts e reinicie
os deployments para que os pods recebam a identidade; valide o consumo e o
envio antes de habilitar produtores. Nunca injete credenciais do runner nos
Secrets dos pods. Confirme que não há credenciais AWS estáticas no ambiente do
consumer que tenham precedência sobre as credenciais do Pod Identity. A
anotação `eks.amazonaws.com/role-arn` é usada por IRSA, não por Pod Identity.

Os três workflows de build/deploy aceitam execução manual (`workflow_dispatch`)
na branch selecionada:

- `tech-challenge-application`: execute o build da branch; ele publica a tag da
  branch e o SHA, sem atualizar `latest` nem disparar deploy automático fora de
  `main`.
- `tech-challenge-services-ms`: a execução manual testa, publica a imagem pelo
  SHA e faz deploy dessa versão no EKS compartilhado.
- `tech-challenge-kubernetes`: execute o deploy manual informando em `image_tag`
  a tag da imagem (ou SHA). Mantenha `enable_services_ms=false` até o MS estar
  implantado e saudável.

Os deploys manuais do Services MS e Kubernetes alteram o cluster EKS
compartilhado; revise a branch e a tag antes de iniciá-los.

## Observabilidade (OpenTelemetry → New Relic)

O MS não usa o agente PHP do New Relic. Ele exporta métricas OTLP/HTTP
(temporalidade delta) para um OpenTelemetry Collector, que repassa ao New Relic:

```
API web (EKS) ──OTLP──> otel-collector (Deployment, k8s/03-observability) ──┐
Lambdas ──OTLP localhost──> layer opentelemetry-collector (extension) ──────┴──> otlp.nr-data.net
```

- **EKS**: `k8s/03-observability/otel-collector.yaml` (ConfigMap, Deployment e
  Service `otel-collector:4318`). O workflow cria o Secret `otel-collector-secret`
  e aplica o collector antes do rollout da API.
- **Lambdas**: as funções rodam fora da VPC e não alcançam o cluster, então cada
  uma carrega a layer `opentelemetry-collector-amd64-<versão>` (variável
  `otel_collector_layer_version`) com a config `otel/lambda-collector.yaml`
  (copiada para `/var/task/collector.yaml` pelo `scripts/build-lambda.sh`). Sem
  `new_relic_license_key`, o Terraform não adiciona a layer e as métricas ficam
  desligadas.
- **PHP**: `config/telemetry.php`. Só exporta com `OTEL_METRICS_EXPORTER=otlp`
  (padrão `none`, usado em dev e nos testes). A API exporta no fim de cada
  request; as Lambdas, no fim de cada invocação.

Secret do repositório necessário: `NEW_RELIC_LICENSE_KEY` (ingest license key,
a mesma do `nri-bundle` no repositório Kubernetes).

| Métrica | Tipo | Atributos | Origem |
|---|---|---|---|
| `service_order.received` | counter | `service_order.status` | O.S. importada (`ServiceOrderCreated`) |
| `service_order.status.transitions` | counter | `service_order.previous_status`, `service_order.status`, `service_order.event_type` | status aplicado no MS |
| `service_order.service.started` / `.finished` | counter | — | início/fim de serviço pela API |
| `service_order.service.duration` | histogram (s) | — | início → fim de um serviço |
| `service_order.execution.duration` | histogram (s) | — | primeiro início → último fim da O.S. |
| `service_order.execution.rejected` | counter | `service_order.operation` (`start`/`finish`) | regra de negócio recusou (422) |
| `messaging.process.duration` | histogram (s) | `messaging.destination.name`, `service_order.event_type`, `outcome` (`processed`/`skipped`/`invalid`/`failed`), `error.type` | cada mensagem SQS consumida |
| `messaging.client.sent.messages` | counter | `messaging.destination.name`, `service_order.event_type`, `error.type` | publicação da outbox |
| `outbox.publish.lag` | histogram (s) | idem | gravação na outbox → envio ao SQS |
| `http.server.request.duration` | histogram (s) | `http.request.method`, `http.route`, `http.response.status_code` | rotas da API (sem `/up`) |

Os atributos não levam IDs de O.S./cliente/veículo (cardinalidade); esses ficam
nos logs. Exemplos de NRQL:

```sql
FROM Metric SELECT sum(service_order.status.transitions) WHERE service.name = 'services-ms' FACET service_order.status TIMESERIES
FROM Metric SELECT percentile(service_order.service.duration, 50, 95) WHERE service.name = 'services-ms' TIMESERIES
FROM Metric SELECT count(messaging.process.duration) WHERE service.name = 'services-ms' FACET outcome, messaging.destination.name
```

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
