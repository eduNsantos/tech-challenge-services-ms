
variable "aws_region" {
  description = "Região da AWS para provisionar os recursos"
  type        = string
  default     = "us-east-1"
}

variable "project_name" {
  description = "Nome base do projeto"
  type        = string
  default     = "services-ms"
}

variable "enable_pod_identity" {
  description = "Provisiona o agente EKS Pod Identity e as roles dedicadas aos workers"
  type        = bool
  default     = false
}

variable "db_username" {
  description = "Usuário do banco de dados"
  type        = string
  default     = "admin"
  sensitive   = true
}

variable "db_password" {
  description = "Senha do banco de dados"
  type        = string
  sensitive   = true
}

variable "db_name" {
  description = "Nome do banco de dados"
  type        = string
  default     = "services_ms"
}

variable "jwt_secret" {
  description = "secret do jwt"
  type        = string
}
variable "bref_php_layer_version" {
  description = "Versão da layer php-84 do Bref na região (ver vendor/bref/bref/layers.json)"
  type        = number
  default     = 43
}

variable "new_relic_license_key" {
  description = "License key (ingest) do New Relic usada pelo OTel Collector das Lambdas. Vazia desliga as métricas."
  type        = string
  default     = ""
  sensitive   = true
}

variable "new_relic_otlp_endpoint" {
  description = "Endpoint OTLP do New Relic (US: https://otlp.nr-data.net, EU: https://otlp.eu01.nr-data.net)"
  type        = string
  default     = "https://otlp.nr-data.net"
}

variable "otel_collector_layer_version" {
  description = "Versão da layer opentelemetry-collector (github.com/open-telemetry/opentelemetry-lambda, release layer-collector/X.Y.Z), com underscores"
  type        = string
  default     = "0_24_0"
}
