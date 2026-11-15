# Service Order Execution API - Bruno Documentation

Documentação interativa das APIs do Services MS usando Bruno API Client.

## 📋 Estrutura

```
bruno/ServiceOrderExecution/
├── bruno.json                          # Configuração da coleção
├── collection.bru                      # Variáveis globais
└── Authenticated/
    └── ServiceOrder/
        ├── Details/
        │   └── GetDetails.bru          # GET /service-orders/{id}
        └── Services/
            ├── StartService.bru        # POST /service-orders/{id}/services/{serviceId}/start
            └── FinishService.bru       # POST /service-orders/{id}/services/{serviceId}/finish
```

## 🔐 Autenticação

Todas as requisições utilizam **JWT Bearer Token** no header `Authorization`.

### Setup Inicial

1. Obtenha um JWT token da aplicação principal (Tech Challenge Application)
2. Configure a variável `jwtToken` em `collection.bru`
3. Ou adicione o token manualmente no header de cada requisição

**Header esperado:**
```
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

## 📝 Variáveis da Coleção

Edite `collection.bru` para configurar:

- `baseUrl` - URL base da API (default: `http://localhost:8080/api`)
- `jwtToken` - JWT token de autenticação
- `serviceOrderId` - ID da ordem de serviço (UUID)
- `serviceId` - ID do serviço dentro da ordem (UUID)

## 🔄 Fluxo Típico de Execução

1. **Get Details** - Recupera informações da ordem
   ```
   GET /service-orders/{serviceOrderId}
   ```

2. **Aprovação na aplicação** - o status pertence à aplicação. Aprove o orçamento por lá; a O.S. chega ao MS em `em_execucao` pela fila `service-order-status-changed`.

3. **Start Service** - Inicia execução de um serviço
   ```
   POST /service-orders/{serviceOrderId}/services/{serviceId}/start
   ```

4. **Finish Service** - Finaliza execução do serviço
   ```
   POST /service-orders/{serviceOrderId}/services/{serviceId}/finish
   ```

## 📊 Status (somente leitura no MS)

`recebida`, `em_diagnostico`, `aguardando_aprovacao`, `em_execucao`, `finalizada`, `entregue`. Serviços só podem ser iniciados em `em_execucao`. Concluir o último serviço publica `ServiceOrderExecutionFinished`, e a aplicação muda a O.S. para `finalizada`.

## ⚠️ Tratamento de Erros

### Status 422 - Unprocessable Entity
Retornado quando há violação de regra de negócio:
- O.S. fora de `em_execucao` ou ainda sem status sincronizado pela aplicação
- Tentativa de iniciar serviço já iniciado
- Tentar finalizar serviço não iniciado
- Falta de permissão

**Exemplo de resposta:**
```json
{
  "message": "Serviço já foi iniciado"
}
```

## 🚀 Como Usar no Bruno

1. Abra o Bruno API Client
2. Importe esta coleção (pasta `bruno/ServiceOrderExecution/`)
3. Configure as variáveis em `collection.bru`
4. Execute as requisições na sequência recomendada

## 🔗 Integração com SQS FIFO

Quando um serviço é finalizado, eventos são publicados:

- **Queue:** `services-ms-service-orders-created.fifo`
- **DLQ:** `services-ms-service-orders-created-dlq.fifo`

Mensagens são processadas de forma ordenada e idempotente.

## 📖 Documentação Adicional

Para mais detalhes sobre:
- Padrões de comunicação entre microsserviços
- Outbox pattern e garantias de entrega
- Configuração de filas SQS FIFO

Veja [README.md](../../README.md) no repositório.
