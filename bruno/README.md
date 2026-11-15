# Service Order Execution API - collection Bruno

Collection das rotas do Services MS para o [Bruno](https://www.usebruno.com/). O contrato completo
(schemas, erros, exemplos) está em [`openapi.yaml`](../openapi.yaml), também publicado no Swagger UI
(`http://localhost:8083` no compose; Service `services-ms-swagger` na porta 8082 no EKS).

## 📋 Estrutura

```
bruno/ServiceOrderExecution/
├── bruno.json                          # Configuração da coleção
├── collection.bru                      # Variáveis (baseUrl, IDs)
└── Authenticated/
    ├── folder.bru                      # Auth Bearer herdada por todas as requisições
    └── ServiceOrder/
        ├── Details/
        │   └── GetDetails.bru          # GET /service-orders/{id}
        └── Services/
            ├── StartService.bru        # POST /service-orders/{id}/services/{serviceId}/start
            └── FinishService.bru       # POST /service-orders/{id}/services/{serviceId}/finish
```

## 🔐 Autenticação

Todas as requisições usam **JWT Bearer** emitido pela aplicação (`POST /api/auth/login` do
tech-challenge-application). Os dois serviços compartilham o mesmo `JWT_SECRET`, e o claim `sub` é gravado
como o usuário que iniciou/finalizou o serviço.

1. Faça login na aplicação e copie o `access_token`.
2. No Bruno, abra a pasta **Authenticated** → aba **Auth** e cole o token (sem o prefixo `Bearer`, que o
   Bruno já adiciona).

As três requisições usam `auth: inherit`, ou seja, todas pegam o token dessa pasta. Para usar uma variável,
coloque `{{jwtToken}}` no campo do token e defina `jwtToken` em `collection.bru`.

Sem token, ou com token expirado ou de assinatura inválida, a resposta é `401 {"message": "Unauthenticated."}`.

## 📝 Variáveis da coleção (`collection.bru`)

- `baseUrl`: URL base da API, **sem `/api`** (as rotas do MS não têm prefixo)
  - local: `http://localhost:8000`
  - EKS: `http://<hostname do LB services-ms-web>:8081`
    (`kubectl get svc services-ms-web -n services-ms -o jsonpath='{.status.loadBalancer.ingress[0].hostname}'`)
- `serviceOrderId`: UUID da O.S. (o mesmo da aplicação)
- `serviceId`: UUID do **item de serviço da O.S.** (`services[].id` retornado pelo Get Details), não o
  `service_id` do catálogo
- `jwtToken`: opcional, só se você referenciar a variável na pasta **Authenticated**

## 🔄 Fluxo típico

1. **Aprovação na aplicação**: o status pertence à aplicação. Aprove o orçamento por lá; a O.S. chega
   ao MS em `em_execucao` pela fila `service-order-status-changed.fifo`.
2. **Get Details**: `GET /service-orders/{serviceOrderId}`. Confira o status e pegue os `services[].id`.
3. **Start Service**: `POST /service-orders/{serviceOrderId}/services/{serviceId}/start`
4. **Finish Service**: `POST /service-orders/{serviceOrderId}/services/{serviceId}/finish`

## 📊 Status (somente leitura no MS)

`recebida`, `em_diagnostico`, `aguardando_aprovacao`, `em_execucao`, `finalizada`, `entregue`.

Serviços só podem ser iniciados com a O.S. em `em_execucao`. Cada `start`/`finish` publica
`ServiceOrderServiceStarted`/`ServiceOrderServiceFinished`, e a aplicação grava o início/fim no serviço da O.S.
Concluir o último serviço publica `ServiceOrderExecutionFinished`, e a aplicação muda a O.S. para
`finalizada`. Como essa mudança volta ao MS pela fila, a resposta do último `finish` ainda mostra
`em_execucao`.

## ⚠️ Erros

As mensagens de erro vêm em inglês. Envie `Accept: application/json` para receber o 404 em JSON (sem
esse header o Laravel responde em HTML).

| Status | Quando | `message` |
|---|---|---|
| 401 | Token ausente, expirado ou inválido | `Unauthenticated.` |
| 404 | O.S. ainda não importada ou serviço que não pertence à O.S. | `No query results for model [App\Models\ServiceOrder] <id>` |
| 422 | Serviço já iniciado | `The service was already started.` |
| 422 | O.S. fora de `em_execucao` | `Cannot start a service while the order is em_diagnostico.` |
| 422 | Status ainda não sincronizado pela aplicação | `Service order status has not been synchronized from the application.` |
| 422 | Finalizar sem ter iniciado | `The service was not started.` |
| 422 | Serviço já finalizado | `The service was already finished.` |

## 🚀 Como usar no Bruno

1. Abra o Bruno → **Open Collection** → pasta `bruno/ServiceOrderExecution/`
2. Ajuste `baseUrl` e os IDs em `collection.bru` e o token na pasta **Authenticated**
3. Execute na ordem do fluxo acima

## 🔗 Filas SQS FIFO

| Fila | Sentido | Conteúdo |
|---|---|---|
| `service-orders-created.fifo` | aplicação → MS | O.S. criada com os serviços |
| `service-order-status-changed.fifo` | aplicação → MS | mudança de status (com `sequence`) |
| `service-order-execution.fifo` | MS → aplicação | início/fim de serviço e de execução (outbox) |

Todas usam `MessageGroupId` = id da O.S. e têm DLQ `*-dlq.fifo`. O processamento é ordenado e idempotente
(`event_id`). Detalhes no [README](../README.md) do repositório.
