# Services MS

Microsserviço de execução dos serviços da O.S. (status, início e fim de cada serviço).

- Entrada: fila SQS FIFO `service-orders-created` (comando `php artisan sqs:consume-service-orders`), com eventos de criação e aprovação/rejeição ordenados por O.S.
- Saída: fila SQS FIFO `service-order-status-changed`, via outbox (comando `php artisan outbox:publish`).
- API (JWT HS256, mesmo `JWT_SECRET` do monólito): `GET /service-orders/{id}`, `PATCH /service-orders/{id}/status`, `POST /service-orders/{id}/services/{sid}/start|finish`.
- O workflow deste repositório publica a imagem e implanta os manifests em `k8s/`; a infraestrutura AWS é gerenciada separadamente.

O evento de aprovação/rejeição usa o contrato `ServiceOrderApprovalChanged` na mesma fila FIFO de criação (`MessageGroupId` = id da O.S.). Isso mantém a decisão atrás da criação para cada ordem. Aprovação sincroniza para `em_execucao` e rejeição para `em_diagnostico`; o MS publica a mudança de status na fila de retorno como nos demais fluxos.

## Infraestrutura

O Terraform em `infra/` consulta a VPC `main` e as subnets `sub_a`/`sub_b` do
repositório Kubernetes com data sources. O RDS do MS usa essa VPC, não é
publicamente acessível e permite MySQL somente a partir do CIDR da VPC.

O RDS atual está na VPC antiga. A próxima aplicação do Terraform substituirá o
banco e removerá os recursos de rede exclusivos do MS; os dados não são
migrados para o novo RDS. Não aplique antes de revisar o plano e confirmar que
o conteúdo do banco antigo pode ser descartado. Após a substituição, atualize o
GitHub Actions Secret `DB_HOST` com o endpoint novo e confirme que
`DB_USERNAME`/`DB_PASSWORD` correspondem ao RDS criado antes do deploy.

## Deploy no EKS

O workflow `.github/workflows/ci-cd.yml` usa GitHub Actions Secrets para o
deploy. Configure `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` e
`AWS_SESSION_TOKEN` para autenticar o runner no EKS; esses valores são usados
somente pelo runner e não devem ser incluídos nos Secrets dos Pods. Para a
aplicação, o workflow usa `DB_HOST`, `SQS_SERVICE_ORDERS_CREATED_URL`,
`SQS_SERVICE_ORDER_STATUS_CHANGED_URL`, `APP_KEY`, `DB_USERNAME`,
`DB_PASSWORD` e `JWT_SECRET`. `GHCR_USERNAME` e `GHCR_TOKEN` são necessários
para pull da imagem privada.

Os Pods precisam de uma identidade IAM para acessar SQS. O ambiente Learner Lab
usa temporariamente a role dos nodes do EKS, que não isola as permissões por
namespace ou ServiceAccount; substitua esse acesso por IRSA ou EKS Pod Identity
com uma role dedicada antes de produção. Mantenha credenciais do runner nos
GitHub Actions Secrets e não as injete nos Secrets dos Pods.

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
